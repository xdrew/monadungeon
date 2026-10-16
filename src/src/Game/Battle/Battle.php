<?php

declare(strict_types=1);

namespace App\Game\Battle;

// BattleResult is now in the same namespace
use App\Game\Duel\DuelSide;
use App\Game\Duel\DuelSideCompleted;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\DoctrineDBAL\ItemArrayJsonType;
use App\Game\Item\Item;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetActivePlayers;
use App\Game\Player\GetPlayer;
use App\Game\Player\PickItem;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Turn\EndTurn;
use App\Game\Turn\Error\NotYourTurnException;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Infrastructure\Doctrine\AggregateRoot;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Telephantast\MessageBus\EntityHandler\FindBy;
use Telephantast\MessageBus\EntityHandler\Property;
use Telephantast\MessageBus\Handler\Mapping\Handler;
use Telephantast\MessageBus\MessageContext;

#[Entity]
#[Table(schema: 'battle')]
#[FindBy(['battleId' => new Property('battleId')])]
class Battle extends AggregateRoot
{
    /** @var array<int> */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    public array $diceResults = [];

    #[Column(type: Types::INTEGER)]
    public int $totalDamage = 0;

    /**
     * @var array<Item>
     */
    #[Column(type: ItemArrayJsonType::class, nullable: true, columnDefinition: 'jsonb')]
    public ?array $usedItems = null;

    #[Column(type: Types::STRING, nullable: true)]
    public ?string $fromPosition = null;

    #[Column(type: Types::STRING, nullable: true)]
    public ?string $toPosition = null;

    /** @var array<string, mixed>|null */
    #[Column(type: JsonType::class, nullable: true, columnDefinition: 'jsonb')]
    private ?array $monsterData = null;

    #[Column(type: Types::BOOLEAN)]
    private bool $battleCompleted = false;

    /**
     * Warrior "Double Attack": true once the dice have been rerolled in this battle.
     */
    #[Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $rerollUsed = false;

    /**
     * The discarded first roll when "Double Attack" was used.
     *
     * @var array<int>|null
     */
    #[Column(type: JsonType::class, nullable: true, columnDefinition: 'jsonb')]
    private ?array $firstDiceResults = null;

    /**
     * Swordsman "Combat Training": how many times a die showing 1 was rerolled in the current roll.
     */
    #[Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $onesRerolled = 0;

    /**
     * Warlock "Sacrifice": 1 HP was paid for +1 strength in this battle (once per combat).
     */
    #[Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $hpSacrificed = false;

    /**
     * Wizard "Magical Affinity": fireballs used in this battle stayed in the inventory.
     * Transient: set and consumed within the same finalizeBattle command, not persisted.
     */
    private bool $spellsKept = false;

    /**
     * Oracle "Farseeing": the battle started on the first move of the turn, +1 to the roll.
     */
    #[Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $farseeing = false;

    /**
     * Valduk "Berserk": +1 at 3–4 HP, +2 at 1–2 HP, fixed when the dice are rolled.
     */
    #[Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $berserkBonus = 0;

    /**
     * Lady Lorraine "Bear Attack": how many dice showing 1 were counted as 6.
     */
    #[Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $onesAsSixes = 0;

    /**
     * Baron Markul "Lightning Attack": he teleported in, a fight that is not won ends in a retreat he chooses.
     */
    #[Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $lightning = false;

    /**
     * Karak: Regent — the Battle Arena duel this Battle is one side of (null for a monster fight).
     */
    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $duelId = null;

    /**
     * Karak: Regent — 'challenger' or 'opponent' (see DuelSide).
     */
    #[Column(type: Types::STRING, nullable: true)]
    private ?string $duelRole = null;

    /**
     * Karak: Regent — the other hero of the duel, for the UI.
     */
    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $duelOpponentId = null;

    /**
     * Karak: Regent — the spells and the sacrifice chosen before the roll (DuelSide::$preset): the side is
     * locked in with them whatever is sent at the finalize.
     *
     * @var array{consumableIds: list<string>, sacrifice: bool}|null
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $duelPreset = null;

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        public readonly Uuid $battleId,
        #[Column(type: UuidType::class)]
        public readonly Uuid $gameId,
        #[Column(type: UuidType::class)]
        public readonly Uuid $playerId,
        #[Column(type: UuidType::class)]
        public readonly Uuid $turnId,
        #[Column(type: Types::INTEGER)]
        public readonly int $guardHP,
        Item $monster,
        FieldPlace $fromPosition,
        FieldPlace $toPosition,
    ) {
        $this->setMonster($monster);
        $this->fromPosition = $fromPosition->toString();
        $this->toPosition = $toPosition->toString();
        $this->battleCompleted = false;
    }

    #[Handler]
    public static function start(StartBattle $command, MessageContext $messageContext): self
    {
        $battle = new self(
            battleId: $command->battleId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            turnId: $command->turnId,
            guardHP: $command->monster->guardHP,
            monster: $command->monster,
            fromPosition: $command->fromPosition,
            toPosition: $command->toPosition,
        );

        if ($command->duel !== null) {
            // Karak: Regent — a duel side: the Duel decided whether Oracle "Farseeing" applies (arena as the first step, or summoned)
            $battle->duelId = $command->duel->duelId;
            $battle->duelRole = $command->duel->role;
            $battle->duelOpponentId = $command->duel->opponentId;
            $battle->duelPreset = $command->duel->preset;
            $battle->farseeing = $command->duel->farseeing;
        } else {
            // Oracle "Farseeing": +1 when no move of the turn has been spent yet (placing a tile is not a move)
            $player = $messageContext->dispatch(new GetPlayer(playerId: $command->playerId));
            if ($player->getSkills()?->fightsBetterOnFirstMove() === true) {
                $turn = $messageContext->dispatch(new GetTurn($command->turnId));
                $battle->farseeing = $turn !== null && $turn->getPerformedActionsCount() === 0;
            }
        }

        $battle->lightning = $command->lightning;

        // Valduk "Berserk": the HP he has when the dice are rolled decides the bonus (monster fight or duel side)
        $roller = $messageContext->dispatch(new GetPlayer(playerId: $command->playerId));
        $rollerHero = $roller->getSkills();
        $battle->berserkBonus = $rollerHero?->berserk() === true ? $rollerHero->berserkBonus($roller->getHP()) : 0;

        // Roll the dice immediately
        $battle->rollDice($messageContext);

        // First battle calculation: weapons only (non-consumables)
        $battle->calculateInitialBattle($messageContext);

        // Karak: Regent — the spells were chosen before the roll: locked in with them now, unless a human
        // Warrior may still reroll (then he is asked, without the spells, as the fight scene does)
        $preset = $command->duel?->preset;
        if ($preset !== null && ($command->duel->auto || $roller->getSkills()?->canRerollBattle() !== true)) {
            $battle->finalizeBattle(new FinalizeBattle(
                battleId: $battle->battleId,
                gameId: $battle->gameId,
                playerId: $battle->playerId,
                turnId: $battle->turnId,
                selectedConsumableIds: $preset['consumableIds'],
                sacrificeHp: $preset['sacrifice'],
            ), $messageContext);

            return $battle;
        }

        // Evaluate the weapons-only roll: auto-finalize a WIN, otherwise ask for consumables / reroll
        $battle->evaluateInitialRoll($command->monster, $command->fromPosition, $command->toPosition, $messageContext);

        // Karak: Regent — nobody can answer for this duel side: it is locked in right away (no reroll, no sacrifice)
        if ($command->duel !== null && $command->duel->auto && !$battle->battleCompleted) {
            $battle->finalizeBattle(new FinalizeBattle(
                battleId: $battle->battleId,
                gameId: $battle->gameId,
                playerId: $battle->playerId,
                turnId: $battle->turnId,
                selectedConsumableIds: $battle->pickAutoConsumables($command->duel->toBeat, $messageContext),
            ), $messageContext);
        }

        return $battle;
    }

    /**
     * Warrior "Double Attack": reroll both dice once per battle, the second roll must be accepted.
     *
     * @throws \DomainException
     */
    #[Handler]
    public function rerollDice(RerollBattleDice $command, MessageContext $messageContext): void
    {
        $game = $messageContext->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            throw new \DomainException('Game is already finished');
        }

        if ($this->battleCompleted) {
            throw new \DomainException('Battle is already completed');
        }

        if ($this->rerollUsed) {
            throw new \DomainException('Dice were already rerolled in this battle, the second roll must be accepted');
        }

        $currentTurnId = $messageContext->dispatch(new GetCurrentTurn($command->gameId));
        if ($currentTurnId === null || !$currentTurnId->equals($command->turnId) || !$this->turnId->equals($command->turnId)) {
            throw new \DomainException('Invalid turn ID');
        }

        // Karak: Regent — the summoned hero of a duel rolls during the challenger's turn
        $currentPlayerId = $messageContext->dispatch(new GetCurrentPlayer($command->gameId));
        $isCurrentPlayer = $currentPlayerId !== null && $currentPlayerId->equals($command->playerId);
        if ((!$isCurrentPlayer && $this->duelId === null) || !$this->playerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        $player = $messageContext->dispatch(new GetPlayer(playerId: $command->playerId));
        if ($player->getSkills()?->canRerollBattle() !== true) {
            throw new \DomainException('Only the Warrior can reroll the dice (Double Attack)');
        }

        $monster = $this->getMonster();
        if ($monster === null || $this->fromPosition === null || $this->toPosition === null) {
            throw new \DomainException('Battle is not properly initialized');
        }

        $this->firstDiceResults = $this->diceResults;
        $this->rerollUsed = true;

        $this->rollDice($messageContext);
        $this->calculateInitialBattle($messageContext);

        $messageContext->dispatch(new BattleDiceRerolled(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            firstDiceResults: $this->firstDiceResults,
            diceResults: $this->diceResults,
        ));

        $this->evaluateInitialRoll(
            $monster,
            FieldPlace::fromString($this->fromPosition),
            FieldPlace::fromString($this->toPosition),
            $messageContext,
        );
    }

    #[Handler]
    public function getInstance(GetBattle $query): self
    {
        return $this;
    }

    public function getDiceResults(): array
    {
        return $this->diceResults;
    }

    public function getTotalDamage(): int
    {
        return $this->totalDamage;
    }

    public function getUsedItems(): ?array
    {
        return $this->usedItems;
    }

    public function getBattleId(): Uuid
    {
        return $this->battleId;
    }

    public function getGameId(): Uuid
    {
        return $this->gameId;
    }

    public function getPlayerId(): Uuid
    {
        return $this->playerId;
    }

    public function getTurnId(): Uuid
    {
        return $this->turnId;
    }

    /**
     * Finalize battle with selected consumables.
     */
    #[Handler]
    public function finalizeBattle(FinalizeBattle $command, MessageContext $messageContext): void
    {
        // Check if game is already finished
        $game = $messageContext->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            // Game is already finished, don't process battle finalization
            return;
        }

        // Karak: Regent — a duel side is locked in once (the turn end settles a pending side)
        if ($this->duelId !== null && $this->battleCompleted) {
            return;
        }
        // Karak: Regent — the spells and the sacrifice were chosen before the roll
        if ($this->duelPreset !== null) {
            $command = new FinalizeBattle(
                battleId: $command->battleId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                turnId: $command->turnId,
                selectedConsumableIds: $this->duelPreset['consumableIds'],
                sacrificeHp: $this->duelPreset['sacrifice'],
            );
        }

        // Get player instance
        $player = $messageContext->dispatch(new GetPlayer(
            playerId: $command->playerId,
        ));

        // Warlock "Sacrifice": the HP is paid before the result is resolved
        if ($command->sacrificeHp) {
            $this->sacrificeHp($command->turnId, $messageContext);
        }

        // Get weapon items (always used)
        $weaponItems = $player->getWeaponItems();

        // Get selected consumable items
        $selectedConsumables = $player->getItemsByIds($command->selectedConsumableIds);

        // Wizard "Magical Affinity": fireballs and Frost Fists still add their bonus but are not consumed
        $keptConsumableIds = [];
        if ($player->getSkills()?->keepsSpellsInCombat() === true) {
            foreach ($selectedConsumables as $selectedConsumable) {
                $selectedConsumable = Item::fromAnything($selectedConsumable);
                if ($selectedConsumable->type === ItemType::FIREBALL || $selectedConsumable->type === ItemType::FROST_FIST) {
                    $keptConsumableIds[] = $selectedConsumable->itemId->toString();
                }
            }
        }
        $this->spellsKept = $keptConsumableIds !== [];

        // Combine weapons and selected consumables
        $finalUsedItems = array_merge($weaponItems, $selectedConsumables);

        // Calculate final damage with selected items
        $weaponDamage = $player->calculateWeaponDamage();
        $consumableDamage = $player->calculateDamageFromItems($command->selectedConsumableIds);
        $totalItemDamage = $weaponDamage + $consumableDamage;

        // Update battle data
        $this->usedItems = $finalUsedItems;
        $this->totalDamage = array_sum($this->diceResults) + $totalItemDamage + $this->sacrificeBonus() + $this->farseeingBonus() + $this->berserkBonus();

        // Calculate final damage breakdown for the event
        $diceRollDamage = array_sum($this->diceResults);
        $itemDamage = $totalItemDamage;

        // Calculate the final result first (before any side effects)
        $monster = $this->getMonster();

        if ($monster !== null && $this->duelId !== null) {
            $this->finalizeDuelSide($command, $monster, $finalUsedItems, $keptConsumableIds, $messageContext);

            return;
        }

        if ($monster !== null) {
            $finalResult = $this->resolveResult($monster, $messageContext);
            // HP is not reduced yet: a loss will cost 1 HP
            $unstoppable = $this->turnContinuesAfterBattle($finalResult, 1, $messageContext);
            $curse = $this->curseInfo($finalResult, $monster, $messageContext);

            // Dispatch event to indicate battle was finalized
            $messageContext->dispatch(new BattleFinalized(
                battleId: $command->battleId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                finalUsedItems: $finalUsedItems,
                finalTotalDamage: $this->totalDamage,
                selectedConsumableIds: $command->selectedConsumableIds,
                keptConsumableIds: $keptConsumableIds,
            ));

            // Consume the selected consumable items (remove them from player's inventory), except the kept ones
            foreach ($command->selectedConsumableIds as $itemId) {
                if (\in_array($itemId, $keptConsumableIds, true)) {
                    continue;
                }
                $messageContext->dispatch(new RemoveItemFromInventory(
                    gameId: $command->gameId,
                    playerId: $command->playerId,
                    itemId: Uuid::fromString($itemId),
                ));
            }

            // Dispatch BattleCompleted event BEFORE processing the result to ensure correct event order
            $messageContext->dispatch(new BattleCompleted(
                battleId: $command->battleId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                result: $finalResult,
                diceResults: $this->diceResults,
                diceRollDamage: $diceRollDamage,
                itemDamage: $itemDamage,
                totalDamage: $this->totalDamage,
                monsterHP: $monster->guardHP,
                usedItems: $this->usedItems,
                // No need for consumable selection since this is the final result
                availableConsumables: null,
                needsConsumableConfirmation: false,
                // Add info about item pickup
                itemPickedUp: $finalResult === BattleResult::WIN && $command->pickupItem,
                rerolled: $this->rerollUsed,
                firstDiceResults: $this->firstDiceResults,
                onesRerolled: $this->onesRerolled,
                tieIsWin: $this->playerWinsTies($messageContext),
                unstoppable: $unstoppable,
                hpSacrificed: $this->hpSacrificed,
                spellsKept: $this->spellsKept,
                farseeing: $this->farseeing,
                berserkBonus: $this->berserkBonus,
                onesAsSixes: $this->onesAsSixes,
                curseChoiceRequired: $curse['required'],
                cursedPlayerId: $curse['holder'],
            ));

            // NOW process the battle result (movement, HP changes, turn ending)
            if ($this->monsterData !== null && $this->fromPosition !== null && $this->toPosition !== null) {
                $fromPos = FieldPlace::fromString($this->fromPosition);
                $toPos = FieldPlace::fromString($this->toPosition);

                $this->processBattleResult($monster, $fromPos, $toPos, $messageContext);

                // Handle item pickup if battle was won and pickupItem flag is set
                if ($finalResult === BattleResult::WIN && $command->pickupItem) {
                    // Use PickItem command to properly handle item pickup and field removal
                    // This will handle:
                    // - Adding item to inventory
                    // - Replacing existing item if needed
                    // - Removing item from field
                    // - Recording the turn action
                    $messageContext->dispatch(new PickItem(
                        gameId: $command->gameId,
                        playerId: $command->playerId,
                        turnId: $this->turnId,
                        position: $toPos,
                        itemIdToReplace: $command->replaceItemId ? Uuid::fromString($command->replaceItemId) : null,
                    ));
                }
            }

            // End the turn after battle processing (a reincarnating hero ends it after choosing a fountain,
            // an Unstoppable Swordsman keeps his turn)
            $awaitingReincarnation = $finalResult === BattleResult::LOSE
                && $messageContext->dispatch(new GetPlayer($command->playerId))->isAwaitingReincarnation();
            // HP has already been reduced by processBattleResult
            $unstoppable = $this->turnContinuesAfterBattle($finalResult, 0, $messageContext);
            // Baron Markul "Lightning Attack" not won: the turn ends once he has chosen where to retreat
            $retreatPending = $this->lightning && $finalResult !== BattleResult::WIN;
            // Valduk "Tireless": a win whose loot he did not take keeps the turn open, he may leave the loot and
            // keep moving (the client ends the turn itself when he leaves the loot without Tireless)
            $tirelessChoice = $finalResult === BattleResult::WIN && !$command->pickupItem
                && $messageContext->dispatch(new GetPlayer($command->playerId))->getSkills()?->tireless() === true;
            if (!$awaitingReincarnation && !$unstoppable && !$retreatPending && !$tirelessChoice) {
                $messageContext->dispatch(new EndTurn(
                    turnId: $this->turnId,
                    gameId: $this->gameId,
                    playerId: $this->playerId,
                    at: new \DateTimeImmutable(),
                ));
            }
        } else {
            // This should not happen with properly initialized battles
            // But we'll try to handle it anyway
            $monster = $this->getMonster();
            if ($monster !== null) {
                $finalResult = $this->resolveResult($monster, $messageContext);

                // Try to get player position and handle movement
                try {
                    $currentPosition = $messageContext->dispatch(new GetPlayerPosition($command->gameId, $command->playerId));
                } catch (\RuntimeException) {
                    $currentPosition = null;
                }

                if ($currentPosition !== null && $finalResult !== BattleResult::WIN) {
                    // We don't know the exact from position, so we can't move them back properly
                    // But we should still handle HP reduction for losses
                    if ($finalResult === BattleResult::LOSE) {
                        $messageContext->dispatch(new ReducePlayerHP(
                            playerId: $command->playerId,
                            gameId: $command->gameId,
                            amount: 1,
                            turnId: $command->turnId,
                        ));
                    }
                }
            }
        }
    }

    public function getMonster(): ?Item
    {
        if ($this->monsterData === null) {
            return null;
        }

        return Item::fromAnything($this->monsterData);
    }

    public function setMonster(Item $monster): void
    {
        $this->monsterData = [
            'name' => $monster->name->value,
            'type' => $monster->type->value,
            'guardHP' => $monster->guardHP,
            'treasureValue' => $monster->treasureValue,
            'guardDefeated' => $monster->guardDefeated,
            'itemId' => $monster->itemId->toString(),
            'endsGame' => $monster->endsGame,
        ];
    }

    /**
     * Whether the fighting player's hero turns ties into victories (Thief "Backstab").
     */
    private function playerWinsTies(MessageContext $messageContext): bool
    {
        $player = $messageContext->dispatch(new GetPlayer(playerId: $this->playerId));

        return $player->getSkills()?->winsTies() === true;
    }

    /**
     * Warlock "Sacrifice": strength bonus bought with HP in this battle.
     */
    private function sacrificeBonus(): int
    {
        return $this->hpSacrificed ? 1 : 0;
    }

    /**
     * Curse of Karak: after beating a mummy a human slayer in a game with other players has to pick the victim.
     * The queries run only in that case, so ordinary fights do not touch the Game aggregate.
     *
     * @return array{required: bool, holder: ?string}
     */
    private function curseInfo(BattleResult $result, Item $monster, MessageContext $messageContext): array
    {
        if ($result !== BattleResult::WIN || $monster->name !== ItemName::MUMMY) {
            return ['required' => false, 'holder' => null];
        }

        $players = $messageContext->dispatch(new GetActivePlayers($this->gameId));
        $player = $messageContext->dispatch(new GetPlayer(playerId: $this->playerId));
        $game = $messageContext->dispatch(new GetGame($this->gameId));

        return [
            'required' => \count($players) > 1 && !$player->isAi(),
            'holder' => $game->getCursedPlayerId()?->toString(),
        ];
    }

    /**
     * Oracle "Farseeing": +1 to the roll when the battle started on the first move of the turn.
     */
    private function farseeingBonus(): int
    {
        return $this->farseeing ? 1 : 0;
    }

    /**
     * Valduk "Berserk": strength bonus for the HP he had when the dice were rolled.
     */
    private function berserkBonus(): int
    {
        return $this->berserkBonus;
    }

    /**
     * Warlock "Sacrifice": pay 1 HP for +1 strength, once per combat. Paying the last HP knocks the hero out
     * for his next turn (Player::reduceHP dispatches PlayerStunned).
     *
     * @throws \DomainException
     */
    private function sacrificeHp(Uuid $turnId, MessageContext $messageContext): void
    {
        $player = $messageContext->dispatch(new GetPlayer(playerId: $this->playerId));
        if ($player->getSkills()?->sacrificesHp() !== true) {
            throw new \DomainException('Only the Warlock can sacrifice HP (Sacrifice)');
        }
        if ($this->hpSacrificed) {
            throw new \DomainException('HP was already sacrificed in this battle');
        }
        if ($player->getHP() <= 0) {
            throw new \DomainException('No HP left to sacrifice');
        }

        $messageContext->dispatch(new ReducePlayerHP(
            playerId: $this->playerId,
            gameId: $this->gameId,
            amount: 1,
            turnId: $turnId,
        ));
        $this->hpSacrificed = true;
    }

    /**
     * Swordsman "Unstoppable": the turn continues after this battle when the hero rolled a 6
     * and is not knocked out by the result.
     *
     * @param int $hpLoss HP the player is about to lose (or has just lost) because of the result
     */
    private function turnContinuesAfterBattle(BattleResult $result, int $hpLoss, MessageContext $messageContext): bool
    {
        $player = $messageContext->dispatch(new GetPlayer(playerId: $this->playerId));
        if ($player->getSkills()?->continuesTurnOnSix() !== true) {
            return false;
        }

        if (!\in_array(6, $this->diceResults, true)) {
            return false;
        }

        $hpAfterBattle = $player->getHP() - ($result === BattleResult::LOSE ? $hpLoss : 0);

        return $hpAfterBattle > 0;
    }

    /**
     * Battle result for the current total damage, with hero skills applied.
     */
    private function resolveResult(Item $monster, MessageContext $messageContext): BattleResult
    {
        $result = $monster->attack($this->totalDamage);
        if ($result === BattleResult::DRAW && $this->playerWinsTies($messageContext)) {
            return BattleResult::WIN;
        }

        return $result;
    }

    /**
     * Evaluate the weapons-only roll (first roll or Double Attack reroll).
     * A WIN is final and processed immediately; otherwise the player chooses consumables (and may reroll if the hero can).
     */
    private function evaluateInitialRoll(
        Item $monster,
        FieldPlace $fromPosition,
        FieldPlace $toPosition,
        MessageContext $messageContext,
    ): void {
        // Calculate dice roll and item damage separately
        $diceRollDamage = array_sum($this->diceResults);
        $itemDamage = $this->totalDamage - $diceRollDamage - $this->sacrificeBonus() - $this->farseeingBonus() - $this->berserkBonus();

        // Check initial battle result (weapons only)
        $initialResult = $this->resolveResult($monster, $messageContext);
        $tieIsWin = $this->playerWinsTies($messageContext);
        // Swordsman "Unstoppable": HP is not reduced yet, a loss will cost 1 HP
        $unstoppable = $this->duelId === null && $this->turnContinuesAfterBattle($initialResult, 1, $messageContext);

        // Karak: Regent — the challenger has nothing to beat yet: he always decides on consumables and locks in his strength
        $isChallenger = $this->duelRole === DuelSide::CHALLENGER;
        if ($isChallenger) {
            $initialResult = BattleResult::DRAW;
        }

        // Karak: Regent — the summoned hero beats the challenger's total with weapons alone: his side is done
        // (not with spells chosen before the roll: they are spent anyway, at the finalize)
        if ($initialResult === BattleResult::WIN && $this->duelId !== null && $this->duelPreset === null) {
            $messageContext->dispatch(new BattleCompleted(
                battleId: $this->battleId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                result: $initialResult,
                diceResults: $this->diceResults,
                diceRollDamage: $diceRollDamage,
                itemDamage: $itemDamage,
                totalDamage: $this->totalDamage,
                monsterHP: $monster->guardHP,
                usedItems: $this->usedItems,
                rerolled: $this->rerollUsed,
                firstDiceResults: $this->firstDiceResults,
                onesRerolled: $this->onesRerolled,
                tieIsWin: $tieIsWin,
                hpSacrificed: $this->hpSacrificed,
                spellsKept: $this->spellsKept,
                farseeing: $this->farseeing,
                berserkBonus: $this->berserkBonus,
                onesAsSixes: $this->onesAsSixes,
                duel: $this->duelPayload($monster),
            ));
            $this->completeDuelSide($messageContext);

            return;
        }

        // If player wins with weapons only, that's the final result
        if ($initialResult === BattleResult::WIN) {
            $curse = $this->curseInfo($initialResult, $monster, $messageContext);
            // Dispatch battle completed event BEFORE processing result to ensure correct event order
            $messageContext->dispatch(new BattleCompleted(
                battleId: $this->battleId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                result: $initialResult, // Use initial result here
                diceResults: $this->diceResults,
                diceRollDamage: $diceRollDamage,
                itemDamage: $itemDamage,
                totalDamage: $this->totalDamage,
                monsterHP: $monster->guardHP,
                usedItems: $this->usedItems,
                rerolled: $this->rerollUsed,
                firstDiceResults: $this->firstDiceResults,
                onesRerolled: $this->onesRerolled,
                tieIsWin: $tieIsWin,
                unstoppable: $unstoppable,
                hpSacrificed: $this->hpSacrificed,
                spellsKept: $this->spellsKept,
                farseeing: $this->farseeing,
                berserkBonus: $this->berserkBonus,
                onesAsSixes: $this->onesAsSixes,
                curseChoiceRequired: $curse['required'],
                cursedPlayerId: $curse['holder'],
            ));

            // Process the battle result after BattleCompleted event
            $this->processBattleResult($monster, $fromPosition, $toPosition, $messageContext);

            return;
        }

        // Player draws or loses with weapons only - give them the choice to use consumables (or reroll)
        // Don't process the battle result yet, just send the initial result to frontend
        $player = $messageContext->dispatch(new GetPlayer(playerId: $this->playerId));
        $availableConsumables = $player->getConsumableItems();
        $canReroll = !$this->rerollUsed && $player->getSkills()?->canRerollBattle() === true;
        // Warlock "Sacrifice": offered once per combat while the hero still has HP to pay
        $canSacrificeHp = !$this->hpSacrificed && $player->getSkills()?->sacrificesHp() === true && $player->getHP() > 0;

        // Dispatch battle completed event with consumable selection data
        // The frontend will show the consumable selection interface
        $messageContext->dispatch(new BattleCompleted(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            result: $initialResult, // Show the initial result (DRAW or LOSE)
            diceResults: $this->diceResults,
            diceRollDamage: $diceRollDamage,
            itemDamage: $itemDamage,
            totalDamage: $this->totalDamage,
            monsterHP: $monster->guardHP,
            usedItems: $this->usedItems, // Show only weapons used initially
            // Additional data for frontend consumable selection
            availableConsumables: $availableConsumables,
            needsConsumableConfirmation: true,
            canReroll: $canReroll,
            rerolled: $this->rerollUsed,
            firstDiceResults: $this->firstDiceResults,
            onesRerolled: $this->onesRerolled,
            tieIsWin: $tieIsWin,
            unstoppable: $unstoppable,
            canSacrificeHp: $canSacrificeHp,
            hpSacrificed: $this->hpSacrificed,
            spellsKept: $this->spellsKept,
            farseeing: $this->farseeing,
            berserkBonus: $this->berserkBonus,
            onesAsSixes: $this->onesAsSixes,
            duel: $this->duelPayload($monster),
        ));
    }

    /**
     * Karak: Regent — the duel side payload for the UI (null for a monster fight).
     *
     * @return array{duelId: string, role: string, opponentId: string, toBeat: ?int, preset: bool}|null
     */
    private function duelPayload(Item $monster): ?array
    {
        if ($this->duelId === null || $this->duelRole === null || $this->duelOpponentId === null) {
            return null;
        }

        return [
            'duelId' => $this->duelId->toString(),
            'role' => $this->duelRole,
            'opponentId' => $this->duelOpponentId->toString(),
            'toBeat' => $this->duelRole === DuelSide::CHALLENGER ? null : $monster->guardHP,
            // the spells were chosen before the roll: the fight scene only offers the reroll
            'preset' => $this->duelPreset !== null,
        ];
    }

    /**
     * Karak: Regent — lock in a duel side: consumables are spent as in a fight, but there is no monster to
     * process — no FIGHT_MONSTER action, no movement, no turn end, no item pickup. The Duel compares the totals.
     *
     * @param array<Item> $finalUsedItems
     * @param list<string> $keptConsumableIds
     */
    private function finalizeDuelSide(
        FinalizeBattle $command,
        Item $monster,
        array $finalUsedItems,
        array $keptConsumableIds,
        MessageContext $messageContext,
    ): void {
        $result = $this->duelRole === DuelSide::CHALLENGER ? BattleResult::DRAW : $this->resolveResult($monster, $messageContext);
        $diceRollDamage = array_sum($this->diceResults);

        $messageContext->dispatch(new BattleFinalized(
            battleId: $command->battleId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            finalUsedItems: $finalUsedItems,
            finalTotalDamage: $this->totalDamage,
            selectedConsumableIds: $command->selectedConsumableIds,
            keptConsumableIds: $keptConsumableIds,
        ));

        foreach ($command->selectedConsumableIds as $itemId) {
            if (\in_array($itemId, $keptConsumableIds, true)) {
                continue;
            }
            $messageContext->dispatch(new RemoveItemFromInventory(
                gameId: $command->gameId,
                playerId: $command->playerId,
                itemId: Uuid::fromString($itemId),
            ));
        }

        $messageContext->dispatch(new BattleCompleted(
            battleId: $command->battleId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            result: $result,
            diceResults: $this->diceResults,
            diceRollDamage: $diceRollDamage,
            itemDamage: $this->totalDamage - $diceRollDamage - $this->sacrificeBonus() - $this->farseeingBonus() - $this->berserkBonus(),
            totalDamage: $this->totalDamage,
            monsterHP: $monster->guardHP,
            usedItems: $this->usedItems,
            availableConsumables: null,
            needsConsumableConfirmation: false,
            rerolled: $this->rerollUsed,
            firstDiceResults: $this->firstDiceResults,
            onesRerolled: $this->onesRerolled,
            tieIsWin: $this->playerWinsTies($messageContext),
            hpSacrificed: $this->hpSacrificed,
            spellsKept: $this->spellsKept,
            farseeing: $this->farseeing,
            berserkBonus: $this->berserkBonus,
            onesAsSixes: $this->onesAsSixes,
            duel: $this->duelPayload($monster),
        ));

        $this->completeDuelSide($messageContext);
    }

    /**
     * Karak: Regent — tell the Duel this hero's strength is final.
     */
    private function completeDuelSide(MessageContext $messageContext): void
    {
        if ($this->duelId === null || $this->duelRole === null) {
            return;
        }
        $this->battleCompleted = true;

        $diceRollDamage = array_sum($this->diceResults);
        $messageContext->dispatch(new DuelSideCompleted(
            duelId: $this->duelId,
            gameId: $this->gameId,
            battleId: $this->battleId,
            role: $this->duelRole,
            playerId: $this->playerId,
            total: $this->totalDamage,
            diceResults: array_values($this->diceResults),
            itemDamage: $this->totalDamage - $diceRollDamage - $this->sacrificeBonus() - $this->farseeingBonus() - $this->berserkBonus(),
            usedItems: array_values(array_map(static fn(Item|array $item): array => Item::fromAnything($item)->toArray(), $this->usedItems ?? [])),
            hpSacrificed: $this->hpSacrificed,
            farseeing: $this->farseeing,
            berserkBonus: $this->berserkBonus,
            onesAsSixes: $this->onesAsSixes,
            rerolled: $this->rerollUsed,
            onesRerolled: $this->onesRerolled,
            tieIsWin: $this->playerWinsTies($messageContext),
            spellsKept: $this->spellsKept,
        ));
    }

    /**
     * Karak: Regent — an automatic duel side spends the fewest damage spells (strongest first) that turn the roll
     * into a win against $toBeat (an equal total is enough for a hero who wins ties); none when they cannot.
     *
     * @return list<string>
     */
    private function pickAutoConsumables(?int $toBeat, MessageContext $messageContext): array
    {
        if ($toBeat === null) {
            return [];
        }

        $needed = ($this->playerWinsTies($messageContext) ? $toBeat : $toBeat + 1) - $this->totalDamage;
        if ($needed <= 0) {
            return [];
        }

        $player = $messageContext->dispatch(new GetPlayer(playerId: $this->playerId));
        $spells = array_values(array_filter(
            array_map(static fn(Item|array $item): Item => Item::fromAnything($item), $player->getConsumableItems()),
            static fn(Item $item): bool => $item->type->getDamage() > 0,
        ));
        usort($spells, static fn(Item $a, Item $b): int => $b->type->getDamage() <=> $a->type->getDamage());

        $picked = [];
        $sum = 0;
        foreach ($spells as $spell) {
            $picked[] = $spell->itemId->toString();
            $sum += $spell->type->getDamage();
            if ($sum >= $needed) {
                return $picked;
            }
        }

        return [];
    }

    /**
     * Roll two six-sided dice.
     */
    private function rollDice(MessageContext $messageContext): void
    {
        // Get the field to check for test dice rolls
        $field = $messageContext->dispatch(new GetField($this->gameId));

        $this->diceResults = [
            $field->getNextDiceRoll(1, 6),
            $field->getNextDiceRoll(1, 6),
        ];

        // Swordsman "Combat Training": a die showing 1 is rerolled until it shows something better
        $this->onesRerolled = 0;
        $player = $messageContext->dispatch(new GetPlayer(playerId: $this->playerId));
        if ($player->getSkills()?->rerollsOnes() === true) {
            foreach ($this->diceResults as $index => $value) {
                while ($value === 1) {
                    ++$this->onesRerolled;
                    $value = $field->getNextDiceRoll(1, 6);
                }
                $this->diceResults[$index] = $value;
            }
        }

        // Lady Lorraine "Bear Attack": a die showing 1 counts as 6
        $this->onesAsSixes = 0;
        if ($player->getSkills()?->bearAttack() === true) {
            foreach ($this->diceResults as $index => $value) {
                if ($value === 1) {
                    ++$this->onesAsSixes;
                    $this->diceResults[$index] = 6;
                }
            }
        }

        $this->totalDamage = array_sum($this->diceResults) + $this->farseeingBonus() + $this->berserkBonus();
    }

    /**
     * Calculate initial battle with weapons only (non-consumables).
     */
    private function calculateInitialBattle(MessageContext $messageContext): void
    {
        // Get player instance
        $player = $messageContext->dispatch(new GetPlayer(
            playerId: $this->playerId,
        ));

        // Get weapon items only (non-consumables)
        $this->usedItems = $player->getWeaponItems();

        // Add their total damage to the roll
        $additionalDamage = $player->calculateWeaponDamage();

        // Update total damage
        $this->totalDamage += $additionalDamage;
    }

    /**
     * Calculate battle with all items including consumables.
     */
    private function calculateBattleWithConsumables(MessageContext $messageContext): void
    {
        // Reset to base dice damage (plus the Oracle "Farseeing" bonus, which belongs to the roll)
        $this->totalDamage = array_sum($this->diceResults) + $this->farseeingBonus() + $this->berserkBonus();

        // Get player instance
        $player = $messageContext->dispatch(new GetPlayer(
            playerId: $this->playerId,
        ));

        // Get all damage items (weapons + consumables)
        $this->usedItems = $player->getDamageItems();

        // Add their total damage to the roll
        $additionalDamage = $player->calculateItemDamage();

        // Update total damage
        $this->totalDamage += $additionalDamage;
    }

    /**
     * Process the result of the battle.
     */
    private function processBattleResult(
        Item $monster,
        FieldPlace $fromPosition,
        FieldPlace $toPosition,
        MessageContext $messageContext,
    ): BattleResult {
        // Attack the monster (Thief "Backstab": a tie becomes a victory)
        $rawResult = $monster->attack($this->totalDamage);
        $result = $this->resolveResult($monster, $messageContext);
        $backstab = $rawResult === BattleResult::DRAW && $result === BattleResult::WIN;
        // Swordsman "Unstoppable": recorded before the HP change, so a loss still has to be accounted for
        $unstoppable = $this->turnContinuesAfterBattle($result, 1, $messageContext);

        // Calculate dice roll and item damage separately
        $diceRollDamage = array_sum($this->diceResults);
        $itemDamage = $this->totalDamage - $diceRollDamage - $this->sacrificeBonus() - $this->farseeingBonus() - $this->berserkBonus();

        // Record the FIGHT_MONSTER action FIRST (before any HP changes that might trigger stunning)
        // We use a standard format for the monster data to ensure compatibility
        $messageContext->dispatch(new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::FIGHT_MONSTER,
            additionalData: [
                'monster' => [
                    'name' => $monster->name->value,
                    'type' => $monster->type->value,
                    'hp' => $monster->guardHP,
                    'defeated' => $monster->guardDefeated,
                ],
                'diceResults' => $this->diceResults,
                'diceRollDamage' => $diceRollDamage,
                'itemDamage' => $itemDamage,
                'totalDamage' => $this->totalDamage,
                'usedItems' => $this->usedItems,
                'result' => $result->name,
                'fromPosition' => $fromPosition->toString(),
                'toPosition' => $toPosition->toString(),
                'rerolled' => $this->rerollUsed,
                'firstDiceResults' => $this->firstDiceResults,
                'onesRerolled' => $this->onesRerolled,
                'backstab' => $backstab,
                'unstoppable' => $unstoppable,
                'hpSacrificed' => $this->hpSacrificed,
                'spellsKept' => $this->spellsKept,
                'farseeing' => $this->farseeing,
                'berserk' => $this->berserkBonus,
                'onesAsSixes' => $this->onesAsSixes,
                'lightningAttack' => $this->lightning,
                'heroAbility' => implode('+', array_filter([
                    $this->rerollUsed ? 'double_attack' : null,
                    $this->onesRerolled > 0 ? 'combat_training' : null,
                    $backstab ? 'backstab' : null,
                    $unstoppable ? 'unstoppable' : null,
                    $this->hpSacrificed ? 'sacrifice' : null,
                    $this->spellsKept ? 'magical_affinity' : null,
                    $this->farseeing ? 'farseeing' : null,
                    $this->berserkBonus > 0 ? 'berserk' : null,
                    $this->onesAsSixes > 0 ? 'bear_attack' : null,
                    $this->lightning ? 'lightning_attack' : null,
                ])) ?: null,
            ],
            at: new \DateTimeImmutable(),
        ));

        // Curse of Karak: beating a mummy lets the slayer curse another player (the Game decides who and whether)
        if ($result === BattleResult::WIN && $monster->name === ItemName::MUMMY) {
            $messageContext->dispatch(new MummyDefeated(
                gameId: $this->gameId,
                playerId: $this->playerId,
                turnId: $this->turnId,
                battleId: $this->battleId,
            ));
        }

        // Handle player movement and HP changes based on battle result
        $awaitingReincarnation = false;
        if ($result !== BattleResult::WIN) {
            // If the player lost, reduce their HP FIRST (before moving)
            if ($result === BattleResult::LOSE) {
                $messageContext->dispatch(new ReducePlayerHP(
                    playerId: $this->playerId,
                    gameId: $this->gameId,
                    amount: 1,
                    turnId: $this->turnId,
                ));

                // Warrior "Reincarnation": the hero must choose a healing fountain before the turn ends
                $awaitingReincarnation = $messageContext->dispatch(new GetPlayer($this->playerId))->isAwaitingReincarnation();
            }

            if ($this->lightning) {
                // Baron Markul "Lightning Attack": no way back, he chooses an opened tile to retreat to (Field)
                $messageContext->dispatch(new RetreatRequired(
                    gameId: $this->gameId,
                    playerId: $this->playerId,
                    turnId: $this->turnId,
                    position: $toPosition,
                    teleportedFrom: $fromPosition,
                ));
            } else {
                // THEN move player back to previous position
                // This ensures HP is reduced before checking healing fountain
                // Use ResetPlayerPosition to forcefully set the position
                $messageContext->dispatch(new ResetPlayerPosition(
                    gameId: $this->gameId,
                    playerId: $this->playerId,
                    position: $fromPosition,
                ));

                // Also dispatch PlayerMoved event for tracking/UI purposes
                $messageContext->dispatch(new PlayerMoved(
                    gameId: $this->gameId,
                    playerId: $this->playerId,
                    fromPosition: $toPosition,
                    toPosition: $fromPosition,
                    movedAt: new \DateTimeImmutable(),
                    isBattleReturn: true, // Mark this as a battle return movement
                ));
            }
        }

        // Only end the turn for LOSE or DRAW results
        // For WIN, the turn continues so the player can pick up the item
        // For a reincarnating hero, the turn ends after the healing fountain was chosen (Field::reincarnate)
        // For an Unstoppable Swordsman the turn goes on (he may even fight the same monster again)
        // For Markul's Lightning Attack the turn ends once he has chosen where to retreat (Field::chooseRetreat)
        if ($awaitingReincarnation || $unstoppable || ($this->lightning && $result !== BattleResult::WIN)) {
            $this->battleCompleted = true;
        } elseif (!$this->battleCompleted && $result !== BattleResult::WIN) {
            $messageContext->dispatch(new EndTurn(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                at: new \DateTimeImmutable(),
            ));
            $this->battleCompleted = true;
        } elseif ($result === BattleResult::WIN) {
            $this->battleCompleted = true;
        }

        return $result;
    }
}
