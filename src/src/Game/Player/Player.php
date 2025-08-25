<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Bag\DoctrineDBAL\InventoryJsonType;
use App\Game\Battle\MonsterDefeated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemType;
use App\Game\Player\Error\InventoryFullException;
use App\Game\Turn\Error\NotYourTurnException;
use App\Infrastructure\Doctrine\AggregateRoot;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\BooleanType;
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
#[Table(schema: 'player')]
#[FindBy(['playerId' => new Property('playerId')])]
class Player extends AggregateRoot
{
    public const int MAX_HP = 5;
    public const int HP_AFTER_STUN = 1;

    /**
     * Maximum number of items of each type that a player can hold.
     */
    private const int MAX_KEYS = 1;

    private const int MAX_WEAPONS = 2;
    private const int MAX_SPELLS = 3;

    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $characterId = null;

    #[Column(type: BooleanType::class)]
    private bool $ready = false;

    /**
     * External ID from authentication provider (e.g., Privy user ID)
     */
    #[Column(type: Types::STRING, nullable: true)]
    private ?string $externalId = null;

    /**
     * Username from Monad Games ID
     */
    #[Column(type: Types::STRING, nullable: true)]
    private ?string $username = null;

    /**
     * Wallet address from authentication provider
     */
    #[Column(type: Types::STRING, nullable: true)]
    private ?string $walletAddress = null;

    /**
     * Whether this player is controlled by AI
     */
    #[Column(type: BooleanType::class)]
    private bool $isAi = false;

    /**
     * @var array{key: array<Item>, weapon: array<Item>, spell: array<Item>, treasure: array<Item>}
     * Inventory slots by item category
     * Format:
     * [
     *   'key' => [], // Max 1 key
     *   'weapon' => [], // Max 2 weapons
     *   'spell' => [], // Max 3 spells
     *   'treasure' => [], // Unlimited treasures
     * ]
     */
    #[Column(type: InventoryJsonType::class, columnDefinition: 'jsonb')]
    private array $inventory;

    private int $maxHp = self::MAX_HP;

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $playerId,
        #[Column(type: UuidType::class)]
        private readonly Uuid $gameId,
        #[Column(type: Types::INTEGER)]
        private int $hp,
    ) {
        $this->inventory = [
            ItemCategory::KEY->value => [],
            ItemCategory::WEAPON->value => [],
            ItemCategory::SPELL->value => [],
            ItemCategory::TREASURE->value => [],
        ];
    }

    #[Handler]
    public static function onPlayerAddedToGame(PlayerAdded $event): self
    {
        $player = new self(
            playerId: $event->playerId,
            gameId: $event->gameId,
            hp: $event->hp,
        );
        $player->externalId = $event->externalId;
        $player->username = $event->username ?? null;
        $player->walletAddress = $event->walletAddress ?? null;
        $player->isAi = $event->isAi;
        return $player;
    }

    #[Handler]
    public function pickCharacter(PickCharacter $command, MessageContext $messageContext): void
    {
        $this->characterId = $command->characterId;

        $messageContext->dispatch(new CharacterPicked(
            gameId: $command->gameId,
            playerId: $this->playerId,
            characterId: $command->characterId,
        ));
    }

    #[Handler]
    public function getReady(GetReady $command, MessageContext $messageContext): void
    {
        $this->ready = true;

        $messageContext->dispatch(new PlayerReady(
            playerId: $this->playerId,
            gameId: $command->gameId,
        ));
    }

    #[Handler]
    public function reduceHP(ReducePlayerHP $command, MessageContext $messageContext): void
    {
        $oldHp = $this->hp;
        $this->hp = max(0, $this->hp - $command->amount);

        // If HP becomes 0, dispatch PlayerStunned event
        if ($oldHp > 0 && $this->hp === 0) {
            $messageContext->dispatch(new PlayerStunned(
                gameId: $command->gameId,
                playerId: $this->playerId,
                turnId: $command->turnId ?? Uuid::v7(),
            ));
        }
    }

    #[Handler]
    public function resetHP(ResetPlayerHP $command): void
    {
        if ($command->afterStun === true) {
            $this->hp = self::HP_AFTER_STUN;

            return;
        }
        $this->hp = self::MAX_HP;
    }

    /**
     * Add an item to the player's inventory.
     * Places the item in the appropriate category based on its type.
     * @throws InventoryFullException
     */
    #[Handler]
    public function addItem(AddItemToInventory $command, MessageContext $messageContext): void
    {
        // Check if game is already finished
        $game = $messageContext->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            // Game is already finished, don't process inventory changes
            return;
        }
        
        $item = $command->item;
        $added = false;

        // Determine which category this item belongs to
        $category = $this->getInventoryCategoryForItemType($item->type);

        // Check if there's space available in this category
        $maxItems = $this->getMaxItemsForCategory($category);
        $currentCount = \count($this->inventory[$category->value] ?? []);

        if ($maxItems === null || $currentCount < $maxItems) {
            // Add the item to the appropriate category
            $this->inventory[$category->value][] = $item;
            $added = true;
        }

        // Dispatch an event to inform that the item was added
        if ($added) {
            $messageContext->dispatch(new ItemAddedToInventory(
                gameId: $this->gameId,
                playerId: $this->playerId,
                item: $item,
            ));
        } else {
            // Throw exception when inventory is full so API can handle it properly
            throw new InventoryFullException(
                item: $item,
                category: $category,
                maxItems: $maxItems ?? 0,
                currentInventory: $this->inventory[$category->value] ?? [],
            );
        }
    }

    /**
     * Remove an item from the player's inventory.
     */
    #[Handler]
    public function removeItem(RemoveItemFromInventory $command, MessageContext $messageContext): void
    {
        $itemId = $command->itemId;
        $removed = false;
        $removedItem = null;

        // Check each category for the item
        foreach ($this->inventory as $category => $items) {
            foreach ($items as $index => $item) {
                $item = Item::fromAnything($item);
                if ($item->itemId->equals($itemId)) {
                    // Store the item before removing
                    $removedItem = $item;
                    // Remove the item from this category
                    unset($this->inventory[$category][$index]);
                    // Re-index the array
                    $this->inventory[$category] = array_values($this->inventory[$category]);
                    $removed = true;
                    break 2; // Break out of both loops
                }
            }
        }

        // Dispatch an event to inform that the item was removed
        if ($removed && $removedItem !== null) {
            $messageContext->dispatch(new ItemRemovedFromInventory(
                gameId: $this->gameId,
                playerId: $this->playerId,
                item: $removedItem,
            ));
        }
    }

    /**
     * Check if it's this player's turn.
     * @throws NotYourTurnException if the current turn belongs to another player
     */
    public function ensureIsPlayerTurn(MessageContext $messageContext): void
    {
        $currentPlayerId = $messageContext->dispatch(new GetCurrentPlayer($this->gameId));
        if ($currentPlayerId === null || !$this->playerId->equals($currentPlayerId)) {
            throw new NotYourTurnException();
        }
    }

    /**
     * Get all items from the player's inventory across all categories.
     * @return array<Item> All items in player's inventory
     */
    public function getAllItems(): array
    {
        $allItems = [];

        // Collect items from all categories
        foreach ($this->inventory as $items) {
            foreach ($items as $item) {
                $allItems[] = $item;
            }
        }

        return $allItems;
    }

    /**
     * Calculate the total damage modifier from all player's items.
     * @return int Total damage from all items
     */
    public function calculateItemDamage(): int
    {
        $totalDamage = 0;

        // Add damage from weapons
        if (isset($this->inventory[ItemCategory::WEAPON->value])) {
            foreach ($this->inventory[ItemCategory::WEAPON->value] as $weapon) {
                $weapon = Item::fromAnything($weapon);
                $totalDamage += $weapon->type->getDamage();
            }
        }

        // Add damage from spells
        if (isset($this->inventory[ItemCategory::SPELL->value])) {
            foreach ($this->inventory[ItemCategory::SPELL->value] as $spell) {
                $spell = Item::fromAnything($spell);
                $totalDamage += $spell->type->getDamage();
            }
        }

        return $totalDamage;
    }

    /**
     * Calculate damage from weapons only (non-consumables).
     * @return int Total damage from weapons
     */
    public function calculateWeaponDamage(): int
    {
        $totalDamage = 0;

        // Add damage from weapons only
        if (isset($this->inventory[ItemCategory::WEAPON->value])) {
            foreach ($this->inventory[ItemCategory::WEAPON->value] as $weapon) {
                $weapon = Item::fromAnything($weapon);
                $totalDamage += $weapon->type->getDamage();
            }
        }

        return $totalDamage;
    }

    /**
     * Calculate damage from specific item IDs.
     * @param array<string> $itemIds Array of item IDs to include in damage calculation
     * @return int Total damage from specified items
     */
    public function calculateDamageFromItems(array $itemIds): int
    {
        $totalDamage = 0;

        // Check each category for items matching the provided IDs
        foreach ($this->inventory as $items) {
            foreach ($items as $item) {
                $item = Item::fromAnything($item);
                if (\in_array($item->itemId->toString(), $itemIds, true) && $item->type->getDamage() > 0) {
                    $totalDamage += $item->type->getDamage();
                }
            }
        }

        return $totalDamage;
    }

    /**
     * Get weapon items (non-consumables).
     * @return array<Item> All weapon items
     */
    public function getWeaponItems(): array
    {
        $weaponItems = [];

        // Collect weapons
        if (isset($this->inventory[ItemCategory::WEAPON->value])) {
            foreach ($this->inventory[ItemCategory::WEAPON->value] as $weapon) {
                $weapon = Item::fromAnything($weapon);
                $weaponItems[] = $weapon;
            }
        }

        return $weaponItems;
    }

    /**
     * Get consumable items (spells).
     * @return array<Item> All consumable items
     */
    public function getConsumableItems(): array
    {
        $consumableItems = [];

        // Collect spells (which are consumable)
        if (isset($this->inventory[ItemCategory::SPELL->value])) {
            foreach ($this->inventory[ItemCategory::SPELL->value] as $spell) {
                $spell = Item::fromAnything($spell);
                $consumableItems[] = $spell;
            }
        }

        return $consumableItems;
    }

    /**
     * Get items by their IDs.
     * @param array<string> $itemIds Array of item IDs to retrieve
     * @return array<Item> Items matching the provided IDs
     */
    public function getItemsByIds(array $itemIds): array
    {
        $items = [];

        // Check each category for items matching the provided IDs
        foreach ($this->inventory as $inventoryItems) {
            foreach ($inventoryItems as $item) {
                $item = Item::fromAnything($item);
                if (\in_array($item->itemId->toString(), $itemIds, true)) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * Get all damage-dealing items from the player's inventory.
     * @return array<Item> All items that can deal damage
     */
    public function getDamageItems(): array
    {
        $damageItems = [];

        // Collect weapons
        if (isset($this->inventory[ItemCategory::WEAPON->value])) {
            foreach ($this->inventory[ItemCategory::WEAPON->value] as $weapon) {
                $weapon = Item::fromAnything($weapon);
                if ($weapon->type->getDamage() > 0) {
                    $damageItems[] = $weapon;
                }
            }
        }

        // Collect spells that deal damage
        if (isset($this->inventory[ItemCategory::SPELL->value])) {
            foreach ($this->inventory[ItemCategory::SPELL->value] as $spell) {
                $spell = Item::fromAnything($spell);
                if ($spell->type->getDamage() > 0) {
                    $damageItems[] = $spell;
                }
            }
        }

        return $damageItems;
    }

    /**
     * Check if player has an item of specific type available.
     */
    public function hasItemOfType(ItemType $itemType): bool
    {
        // Determine which category to check
        $category = $this->getInventoryCategoryForItemType($itemType);

        // Check if any item in that category matches the type
        foreach ($this->inventory[$category->value] ?? [] as $item) {
            if ($item->type === $itemType) {
                return true;
            }
        }

        return false;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function isAi(): bool
    {
        return $this->isAi;
    }

    public function getCharacterId(): ?Uuid
    {
        return $this->characterId;
    }

    public function getPlayerId(): Uuid
    {
        return $this->playerId;
    }

    public function getGameId(): Uuid
    {
        return $this->gameId;
    }

    public function getHP(): int
    {
        return $this->hp;
    }

    public function getMaxHp(): int
    {
        return $this->maxHp;
    }

    public function isDefeated(): bool
    {
        return $this->hp <= 0;
    }

    public function needsHealing(): bool
    {
        return $this->hp < $this->maxHp;
    }

    /**
     * @return array{key: array<Item>, weapon: array<Item>, spell: array<Item>, treasure: array<Item>}
     */
    public function getInventory(): array
    {
        return $this->inventory;
    }

    /**
     * Handler for QueryPlayerInventory message
     * Returns the player's current inventory data.
     */
    #[Handler]
    public function getPlayerInventory(QueryPlayerInventory $query): array
    {
        // Return the full inventory structure
        return $this->inventory;
    }

    /**
     * Handler for GetPlayer message
     * Returns the player instance.
     */
    #[Handler]
    public function getPlayerInstance(GetPlayer $query): self
    {
        return $this;
    }

    /**
     * Replace an existing item in the inventory with a new one
     * Used when inventory is full and player decides to replace an item.
     */
    #[Handler]
    public function replaceInventoryItem(ReplaceInventoryItem $command, MessageContext $messageContext): void
    {
        $itemIdToReplace = $command->itemIdToReplace;
        $replaced = false;
        $oldItem = null;

        // First, find and remove the existing item
        foreach ($this->inventory as $category => $items) {
            foreach ($items as $index => $item) {
                $item = Item::fromAnything($item);
                if ($item->itemId->equals($itemIdToReplace)) {
                    // Store the old item for event dispatching
                    $oldItem = $item;

                    // Remove the item from this category
                    unset($this->inventory[$category][$index]);
                    // Re-index the array
                    $this->inventory[$category] = array_values($this->inventory[$category]);
                    $replaced = true;
                    break 2; // Break out of both loops
                }
            }
        }

        // Now add the new item if the old one was found and removed
        if ($replaced && $oldItem !== null) {
            // Determine which category this item belongs to
            $category = $this->getInventoryCategoryForItemType($command->newItem->type);

            // Add the item to the appropriate category
            $this->inventory[$category->value][] = $command->newItem;

            // Dispatch both events - first the ItemRemovedFromInventory event
            // then the ItemReplacedInInventory event. This allows proper event handling
            // in systems that listen for item removal events while also letting them
            // know that this was part of a replacement operation.
            $messageContext->dispatch(new ItemRemovedFromInventory(
                gameId: $command->gameId,
                playerId: $command->playerId,
                item: $oldItem,
            ));

            // Dispatch the ItemReplacedInInventory event
            $messageContext->dispatch(new ItemReplacedInInventory(
                gameId: $command->gameId,
                playerId: $command->playerId,
                oldItemId: $command->itemIdToReplace,
                newItem: $command->newItem,
            ));
        }
    }

    /**
     * Handle when a player decides to skip picking up an item.
     */
    #[Handler]
    public function skipItemPickup(SkipItemPickup $command, MessageContext $messageContext): void
    {
        // Just dispatch an event to record that the player skipped the item
        $messageContext->dispatch(new ItemPickupSkipped(
            gameId: $command->gameId,
            playerId: $command->playerId,
            skippedItem: $command->skippedItem,
        ));
    }

    /**
     * Handle monster defeated event to give items to player
     * if there is inventory space available.
     */
    #[Handler]
    public function handleMonsterDefeated(MonsterDefeated $event, MessageContext $messageContext): void
    {
        // Monster defeated - item pickup is now handled manually by the player
        // via the pick-item API endpoint, so we don't do any automatic processing here
    }

    /**
     * Get the external ID (e.g., Privy user ID) for this player
     */
    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    /**
     * Get the username from Monad Games ID
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getWalletAddress(): ?string
    {
        return $this->walletAddress;
    }

    #[Handler]
    public function getPlayerStatus(GetPlayerStatus $query): array
    {
        return [
            'playerId' => $this->playerId->toString(),
            'hp' => $this->hp,
            'isStunned' => $this->isDefeated(),
            'externalId' => $this->externalId,
            'username' => $this->username,
            'inventory' => [
                'items' => $this->inventory,
                'capacity' => $this->getInventoryCapacity(),
                'remaining' => $this->getInventoryCapacity() - $this->getInventoryItemCount(),
            ],
        ];
    }

    // Test mode methods - only for use in tests

    /**
     * Set HP for testing purposes.
     * @internal Only for use in test mode
     */
    public function setTestHp(int $hp): void
    {
        $this->hp = max(0, $hp);
    }

    /**
     * Set max HP for testing purposes.
     * @internal Only for use in test mode
     */
    public function setTestMaxHp(int $maxHp): void
    {
        $this->maxHp = max(1, $maxHp);
    }

    /**
     * Set stun status for testing purposes.
     * @internal Only for use in test mode
     */
    public function setTestStunned(bool $isStunned): void
    {
        // In the actual game, stun is typically controlled by HP
        // For tests, we might need to add a separate stunned flag
        if ($isStunned && $this->hp > 0) {
            $this->hp = 0; // Stunned players have 0 HP
        } elseif (!$isStunned && $this->hp === 0) {
            $this->hp = 1; // Unstun by giving minimal HP
        }
    }

    /**
     * Set position for testing purposes.
     * @internal Only for use in test mode
     */
    public function setTestPosition(int $x, int $y): void
    {
        // Position is typically managed by Field, but for tests we might need this
        // This would require coordination with the Field entity
    }

    /**
     * Set inventory for testing purposes.
     * @param array<string> $items
     * @internal Only for use in test mode
     */
    public function setTestInventory(array $items): void
    {
        $this->inventory = [
            ItemCategory::WEAPON->value => [],
            ItemCategory::SPELL->value => [],
            ItemCategory::KEY->value => [],
            ItemCategory::TREASURE->value => [],
        ];

        foreach ($items as $itemName) {
            // Create items from names and add to inventory
            $item = Item::createFromName($itemName);
            $category = $item->type->getCategory();
            $this->inventory[$category->value][] = $item;
        }
    }

    private function getInventoryCapacity(): int
    {
        return self::MAX_KEYS + self::MAX_WEAPONS + self::MAX_SPELLS;
    }

    private function getInventoryItemCount(): int
    {
        $count = 0;
        foreach ($this->inventory as $category => $items) {
            if ($category !== ItemCategory::TREASURE->value) {
                $count += \count($items);
            }
        }

        return $count;
    }

    private function getInventoryCategoryForItemType(ItemType $itemType): ItemCategory
    {
        return $itemType->getCategory();
    }

    /**
     * Get maximum number of items allowed for a category.
     */
    private function getMaxItemsForCategory(ItemCategory $category): ?int
    {
        return match ($category) {
            ItemCategory::KEY => self::MAX_KEYS,
            ItemCategory::WEAPON => self::MAX_WEAPONS,
            ItemCategory::SPELL => self::MAX_SPELLS,
            ItemCategory::TREASURE => null,
            default => 0,
        };
    }
}
