<?php

declare(strict_types=1);

namespace App\Game\Battle;

// BattleResult is now in the same namespace
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\DoctrineDBAL\ItemArrayJsonType;
use App\Game\Item\Item;
use App\Game\Item\ItemType;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\GetPlayerPosition;
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

        // Oracle "Farseeing": +1 when no move of the turn has been spent yet (placing a tile is not a move)
        $player = $messageContext->dispatch(new GetPlayer(playerId: $command->playerId));
        if ($player->getHero()?->fightsBetterOnFirstMove() === true) {
            $turn = $messageContext->dispatch(new GetTurn($command->turnId));
            $battle->farseeing = $turn !== null && $turn->getPerformedActionsCount() === 0;
        }

        // Roll the dice immediately
        $battle->rollDice($messageContext);

        // First battle calculation: weapons only (non-consumables)
        $battle->calculateInitialBattle($messageContext);

        // Evaluate the weapons-only roll: auto-finalize a WIN, otherwise ask for consumables / reroll
        $battle->evaluateInitialRoll($command->monster, $command->fromPosition, $command->toPosition, $messageContext);

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

        $currentPlayerId = $messageContext->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$currentPlayerId->equals($command->playerId) || !$this->playerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        $player = $messageContext->dispatch(new GetPlayer(playerId: $command->playerId));
        if ($player->getHero()?->canRerollBattle() !== true) {
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

        // Wizard "Magical Affinity": fireballs still add their bonus but are not consumed
        $keptConsumableIds = [];
        if ($player->getHero()?->keepsSpellsInCombat() === true) {
            foreach ($selectedConsumables as $selectedConsumable) {
                $selectedConsumable = Item::fromAnything($selectedConsumable);
                if ($selectedConsumable->type === ItemType::FIREBALL) {
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
        $this->totalDamage = array_sum($this->diceResults) + $totalItemDamage + $this->sacrificeBonus() + $this->farseeingBonus();

        // Calculate final damage breakdown for the event
        $diceRollDamage = array_sum($this->diceResults);
        $itemDamage = $totalItemDamage;

        // Calculate the final result first (before any side effects)
        $monster = $this->getMonster();

        if ($monster !== null) {
            $finalResult = $this->resolveResult($monster, $messageContext);
            // HP is not reduced yet: a loss will cost 1 HP
            $unstoppable = $this->turnContinuesAfterBattle($finalResult, 1, $messageContext);

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
            if (!$awaitingReincarnation && !$unstoppable) {
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

        return $player->getHero()?->winsTies() === true;
    }

    /**
     * Warlock "Sacrifice": strength bonus bought with HP in this battle.
     */
    private function sacrificeBonus(): int
    {
        return $this->hpSacrificed ? 1 : 0;
    }

    /**
     * Oracle "Farseeing": +1 to the roll when the battle started on the first move of the turn.
     */
    private function farseeingBonus(): int
    {
        return $this->farseeing ? 1 : 0;
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
        if ($player->getHero()?->sacrificesHp() !== true) {
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
        if ($player->getHero()?->continuesTurnOnSix() !== true) {
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
        $itemDamage = $this->totalDamage - $diceRollDamage - $this->sacrificeBonus() - $this->farseeingBonus();

        // Check initial battle result (weapons only)
        $initialResult = $this->resolveResult($monster, $messageContext);
        $tieIsWin = $this->playerWinsTies($messageContext);
        // Swordsman "Unstoppable": HP is not reduced yet, a loss will cost 1 HP
        $unstoppable = $this->turnContinuesAfterBattle($initialResult, 1, $messageContext);
        // If player wins with weapons only, that's the final result
        if ($initialResult === BattleResult::WIN) {
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
            ));

            // Process the battle result after BattleCompleted event
            $this->processBattleResult($monster, $fromPosition, $toPosition, $messageContext);

            return;
        }

        // Player draws or loses with weapons only - give them the choice to use consumables (or reroll)
        // Don't process the battle result yet, just send the initial result to frontend
        $player = $messageContext->dispatch(new GetPlayer(playerId: $this->playerId));
        $availableConsumables = $player->getConsumableItems();
        $canReroll = !$this->rerollUsed && $player->getHero()?->canRerollBattle() === true;
        // Warlock "Sacrifice": offered once per combat while the hero still has HP to pay
        $canSacrificeHp = !$this->hpSacrificed && $player->getHero()?->sacrificesHp() === true && $player->getHP() > 0;

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
        ));
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
        if ($player->getHero()?->rerollsOnes() === true) {
            foreach ($this->diceResults as $index => $value) {
                while ($value === 1) {
                    ++$this->onesRerolled;
                    $value = $field->getNextDiceRoll(1, 6);
                }
                $this->diceResults[$index] = $value;
            }
        }

        $this->totalDamage = array_sum($this->diceResults) + $this->farseeingBonus();
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
        $this->totalDamage = array_sum($this->diceResults) + $this->farseeingBonus();

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
        $itemDamage = $this->totalDamage - $diceRollDamage - $this->sacrificeBonus() - $this->farseeingBonus();

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
                'heroAbility' => implode('+', array_filter([
                    $this->rerollUsed ? 'double_attack' : null,
                    $this->onesRerolled > 0 ? 'combat_training' : null,
                    $backstab ? 'backstab' : null,
                    $unstoppable ? 'unstoppable' : null,
                    $this->hpSacrificed ? 'sacrifice' : null,
                    $this->spellsKept ? 'magical_affinity' : null,
                    $this->farseeing ? 'farseeing' : null,
                ])) ?: null,
            ],
            at: new \DateTimeImmutable(),
        ));

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

        // Only end the turn for LOSE or DRAW results
        // For WIN, the turn continues so the player can pick up the item
        // For a reincarnating hero, the turn ends after the healing fountain was chosen (Field::reincarnate)
        // For an Unstoppable Swordsman the turn goes on (he may even fight the same monster again)
        if ($awaitingReincarnation || $unstoppable) {
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
