<?php

declare(strict_types=1);

namespace App\Game\Battle;

// BattleResult is now in the same namespace
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\DoctrineDBAL\ItemArrayJsonType;
use App\Game\Item\Item;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\AddItemToInventory;
use App\Game\Player\GetPlayer;
use App\Game\Player\PickItem;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Turn\EndTurn;
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

        // Roll the dice immediately
        $battle->rollDice($messageContext);

        // First battle calculation: weapons only (non-consumables)
        $battle->calculateInitialBattle($messageContext);

        // Calculate dice roll and item damage separately
        $diceRollDamage = array_sum($battle->diceResults);
        $itemDamage = $battle->totalDamage - $diceRollDamage;

        // Check initial battle result (weapons only)
        $initialResult = $command->monster->attack($battle->totalDamage);
        // If player wins with weapons only, that's the final result
        if ($initialResult === BattleResult::WIN) {
            // Dispatch battle completed event BEFORE processing result to ensure correct event order
            $messageContext->dispatch(new BattleCompleted(
                battleId: $command->battleId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                result: $initialResult, // Use initial result here
                diceResults: $battle->diceResults,
                diceRollDamage: $diceRollDamage,
                itemDamage: $itemDamage,
                totalDamage: $battle->totalDamage,
                monsterHP: $command->monster->guardHP,
                usedItems: $battle->usedItems,
            ));

            // Process the battle result after BattleCompleted event
            $battle->processBattleResult($command->monster, $command->fromPosition, $command->toPosition, $messageContext);
        } else {
            // Player draws or loses with weapons only - give them the choice to use consumables
            // Don't process the battle result yet, just send the initial result to frontend
            $player = $messageContext->dispatch(new GetPlayer(playerId: $command->playerId));
            $availableConsumables = $player->getConsumableItems();

            // Dispatch battle completed event with consumable selection data
            // The frontend will show the consumable selection interface
            $messageContext->dispatch(new BattleCompleted(
                battleId: $command->battleId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                result: $initialResult, // Show the initial result (DRAW or LOSE)
                diceResults: $battle->diceResults,
                diceRollDamage: $diceRollDamage,
                itemDamage: $itemDamage,
                totalDamage: $battle->totalDamage,
                monsterHP: $command->monster->guardHP,
                usedItems: $battle->usedItems, // Show only weapons used initially
                // Additional data for frontend consumable selection
                availableConsumables: $availableConsumables,
                needsConsumableConfirmation: true,
            ));
        }

        return $battle;
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

        // Get weapon items (always used)
        $weaponItems = $player->getWeaponItems();

        // Get selected consumable items
        $selectedConsumables = $player->getItemsByIds($command->selectedConsumableIds);

        // Combine weapons and selected consumables
        $finalUsedItems = array_merge($weaponItems, $selectedConsumables);

        // Calculate final damage with selected items
        $weaponDamage = $player->calculateWeaponDamage();
        $consumableDamage = $player->calculateDamageFromItems($command->selectedConsumableIds);
        $totalItemDamage = $weaponDamage + $consumableDamage;

        // Update battle data
        $this->usedItems = $finalUsedItems;
        $this->totalDamage = array_sum($this->diceResults) + $totalItemDamage;

        // Calculate final damage breakdown for the event
        $diceRollDamage = array_sum($this->diceResults);
        $itemDamage = $this->totalDamage - $diceRollDamage;

        // Calculate the final result first (before any side effects)
        $monster = $this->getMonster();

        if ($monster !== null) {
            $finalResult = $monster->attack($this->totalDamage);

            // Dispatch event to indicate battle was finalized
            $messageContext->dispatch(new BattleFinalized(
                battleId: $command->battleId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                finalUsedItems: $finalUsedItems,
                finalTotalDamage: $this->totalDamage,
                selectedConsumableIds: $command->selectedConsumableIds,
            ));

            // Consume the selected consumable items (remove them from player's inventory)
            foreach ($command->selectedConsumableIds as $itemId) {
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

            // End the turn after battle processing
            $messageContext->dispatch(new EndTurn(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                at: new \DateTimeImmutable(),
            ));
        } else {
            // This should not happen with properly initialized battles
            // But we'll try to handle it anyway
            $monster = $this->getMonster();
            if ($monster !== null) {
                $finalResult = $monster->attack($this->totalDamage);

                // Try to get player position and handle movement
                try {
                    $currentPosition = $messageContext->dispatch(new GetPlayerPosition($command->gameId, $command->playerId));
                } catch (\RuntimeException) {
                    $currentPosition = null;
                }

                if ($currentPosition !== null && $finalResult !== BattleResult::WIN) {
                    // We don't know the exact from position, so we can't move them back properly
                    // But we should still handle HP reduction for losses
                    if ($finalResult === BattleResult::LOOSE) {
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
     * Roll two six-sided dice.
     */
    private function rollDice(MessageContext $messageContext): void
    {
        // Get the field to check for test dice rolls
        $field = $messageContext->dispatch(new GetField($this->gameId));

        $this->diceResults = [
            $field->getNextDiceRoll(1, 1),
            $field->getNextDiceRoll(1, 1),
        ];

        $this->totalDamage = array_sum($this->diceResults);
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
        // Reset to base dice damage
        $this->totalDamage = array_sum($this->diceResults);

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
        // Attack the monster
        $result = $monster->attack($this->totalDamage);

        // Calculate dice roll and item damage separately
        $diceRollDamage = array_sum($this->diceResults);
        $itemDamage = $this->totalDamage - $diceRollDamage;

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
            ],
            at: new \DateTimeImmutable(),
        ));

        // Handle player movement and HP changes based on battle result
        if ($result !== BattleResult::WIN) {
            // If the player lost, reduce their HP FIRST (before moving)
            if ($result === BattleResult::LOOSE) {
                $messageContext->dispatch(new ReducePlayerHP(
                    playerId: $this->playerId,
                    gameId: $this->gameId,
                    amount: 1,
                    turnId: $this->turnId,
                ));
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
        if (!$this->battleCompleted && $result !== BattleResult::WIN) {
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
