<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Bag\DoctrineDBAL\InventoryJsonType;
use App\Game\Battle\MonsterDefeated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\LordKarakRevealed;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\GameLifecycle\PlayerLeftTable;
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

    /**
     * Chosen hero (null = no hero skills).
     */
    #[Column(type: Types::STRING, nullable: true, enumType: HeroType::class)]
    private ?HeroType $hero = null;

    /**
     * True while a reincarnating hero (HP = 0) still has to choose a healing fountain.
     */
    #[Column(type: BooleanType::class, options: ['default' => false])]
    private bool $awaitingReincarnation = false;

    /**
     * Curse of Karak: while the curse token lies on this player the hero's skills are switched off.
     */
    #[Column(type: BooleanType::class, options: ['default' => false])]
    private bool $cursed = false;

    #[Column(type: BooleanType::class)]
    private bool $ready = false;

    /**
     * External identity of the player, if the client sends one.
     */
    #[Column(type: Types::STRING, nullable: true)]
    private ?string $externalId = null;

    /**
     * Display name of the player.
     */
    #[Column(type: Types::STRING, nullable: true)]
    private ?string $username = null;

    /**
     * Whether this player is controlled by AI.
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

    /**
     * Valduk "Berserk": heal only up to this many HP (null = heal fully), so he can stay in his rage.
     */
    #[Column(type: Types::INTEGER, nullable: true)]
    private ?int $healLimit = null;

    /**
     * Karak: Regent, Lord Karak variant — this player became Lord Karak.
     */
    #[Column(type: BooleanType::class, options: ['default' => false])]
    private bool $lordKarak = false;

    /**
     * Karak: Regent, Lord Karak variant — the heroes whose abilities Lord Karak has (hero values).
     *
     * @var list<string>
     */
    #[Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $lordKarakHeroes = [];

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
        $player->isAi = $event->isAi;
        $player->hero = $event->hero;

        return $player;
    }

    #[Handler]
    public function pickHero(PickHero $command, MessageContext $messageContext): void
    {
        if ($this->ready) {
            throw new \DomainException('Cannot change hero after the player is ready');
        }

        $game = $messageContext->dispatch(new GetGame($command->gameId));
        if ($game->isRandomHeroes()) {
            throw new \DomainException('Heroes are dealt at random in this game');
        }

        // Karak: Regent heroes come with the expansion only
        if ($command->hero->isRegent() && !$game->isRegent()) {
            throw new \DomainException($command->hero->label() . ' needs the Karak: Regent expansion');
        }

        // At a table every hero stands once: a hero held by another seat is refused
        if ($game->isOnline()) {
            foreach ($game->getPlayers() as $otherId) {
                if (!$otherId->equals($this->playerId)
                    && $messageContext->dispatch(new GetPlayer($otherId))->getChosenHero() === $command->hero) {
                    throw new \DomainException($command->hero->label() . ' is already taken at this table');
                }
            }
        }

        $this->hero = $command->hero;

        $messageContext->dispatch(new HeroPicked(
            gameId: $command->gameId,
            playerId: $this->playerId,
            hero: $command->hero,
        ));
    }

    /**
     * The seat left a table before the start: it no longer belongs to the account and holds no hero.
     */
    #[Handler]
    public function onLeftTable(PlayerLeftTable $event): void
    {
        $this->externalId = null;
        $this->hero = null;
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

        if ($oldHp > 0 && $this->hp === 0) {
            // Warrior "Reincarnation": instead of being stunned the hero chooses a healing fountain (not while cursed)
            if ($this->getSkills()?->reincarnates() === true) {
                $this->awaitingReincarnation = true;

                $messageContext->dispatch(new ReincarnationRequired(
                    gameId: $command->gameId,
                    playerId: $this->playerId,
                    turnId: $command->turnId ?? Uuid::v7(),
                ));

                return;
            }

            // If HP becomes 0, dispatch PlayerStunned event
            $messageContext->dispatch(new PlayerStunned(
                gameId: $command->gameId,
                playerId: $this->playerId,
                turnId: $command->turnId ?? Uuid::v7(),
            ));
        }
    }

    /**
     * Warrior "Reincarnation": all HP are replenished once the healing fountain was chosen.
     *
     * @throws \DomainException
     */
    #[Handler]
    public function completeReincarnation(CompleteReincarnation $command, MessageContext $messageContext): void
    {
        if (!$this->awaitingReincarnation) {
            throw new \DomainException('Player is not awaiting reincarnation');
        }

        $this->awaitingReincarnation = false;
        $this->hp = self::MAX_HP;

        $messageContext->dispatch(new PlayerReincarnated(
            gameId: $command->gameId,
            playerId: $this->playerId,
            position: $command->position,
            reincarnatedAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * Curse of Karak: the token was placed on this player.
     */
    #[Handler]
    public function onPlayerCursed(PlayerCursed $event): void
    {
        $this->cursed = true;
    }

    /**
     * Curse of Karak: the token left this player.
     */
    #[Handler]
    public function onCurseLifted(CurseLifted $event): void
    {
        $this->cursed = false;
    }

    /**
     * Karak: Regent, Lord Karak variant — this hero became Lord Karak: fully healed (the heal limit does not apply,
     * a stunned hero is back on his feet) with the abilities of the heroes he took over. The Game lifts the curse.
     */
    #[Handler]
    public function onLordKarakRevealed(LordKarakRevealed $event): void
    {
        $this->lordKarak = true;
        $this->lordKarakHeroes = array_map(static fn(HeroType $hero): string => $hero->value, $event->heroes);
        $this->hp = self::MAX_HP;
        $this->awaitingReincarnation = false;
    }

    #[Handler]
    public function resetHP(ResetPlayerHP $command): void
    {
        if ($command->afterStun === true) {
            $this->hp = self::HP_AFTER_STUN;

            return;
        }

        // Valduk "Berserk": he chooses how many HP to heal; healing never takes HP away (cursed: full heal)
        if ($this->healLimit !== null && $this->getSkills()?->choosesHealing() === true) {
            $this->hp = max($this->hp, min(self::MAX_HP, $this->healLimit));

            return;
        }
        $this->hp = self::MAX_HP;
    }

    /**
     * Valduk "Berserk": set how many HP a heal restores at most (null = heal fully).
     *
     * @throws \DomainException
     */
    #[Handler]
    public function setHealLimit(SetHealLimit $command): void
    {
        // His own card (a cursed Valduk keeps the setting for later) or Valduk's skill gained as Lord Karak
        if ($this->hero?->choosesHealing() !== true && $this->getSkills()?->choosesHealing() !== true) {
            throw new \DomainException('Only Valduk chooses how many HP to heal (Berserk)');
        }
        if ($command->hp !== null && ($command->hp < 1 || $command->hp > self::MAX_HP)) {
            throw new \DomainException(sprintf('Heal limit must be between 1 and %d HP', self::MAX_HP));
        }

        $this->healLimit = $command->hp;
    }

    public function getHealLimit(): ?int
    {
        return $this->healLimit;
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

        // Hanna "Dagger Throwing": with the weapon slots full a dagger goes into a free spell slot
        $throwsDagger = $item->type === ItemType::DAGGER && $this->getSkills()?->throwsDaggers() === true;
        if ($throwsDagger && $maxItems !== null && $currentCount >= $maxItems
            && \count($this->inventory[ItemCategory::SPELL->value] ?? []) < self::MAX_SPELLS) {
            $category = ItemCategory::SPELL;
            $maxItems = self::MAX_SPELLS;
            $currentCount = \count($this->inventory[ItemCategory::SPELL->value] ?? []);
        }

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
                // Hanna "Dagger Throwing": a dagger may replace a weapon or anything in the spell slots
                currentInventory: $throwsDagger
                    ? array_merge($this->inventory[ItemCategory::WEAPON->value] ?? [], $this->inventory[ItemCategory::SPELL->value] ?? [])
                    : $this->inventory[$category->value] ?? [],
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
                $totalDamage += $this->itemStrength($weapon);
            }
        }

        // Add damage from spells (Hanna "Dagger Throwing": daggers in the spell slots count while she can throw them)
        if (isset($this->inventory[ItemCategory::SPELL->value])) {
            foreach ($this->inventory[ItemCategory::SPELL->value] as $spell) {
                $spell = Item::fromAnything($spell);
                if ($spell->type === ItemType::DAGGER && $this->getSkills()?->throwsDaggers() !== true) {
                    continue;
                }
                $totalDamage += $this->itemStrength($spell);
            }
        }

        return $totalDamage;
    }

    /**
     * Strength of an item in this hero's hands (Baron Markul "Sword Master": a sword has strength 3).
     */
    public function itemStrength(Item $item): int
    {
        if ($item->type === ItemType::SWORD && $this->getSkills()?->masterOfSword() === true) {
            return 3;
        }

        return $item->type->getDamage();
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
                $totalDamage += $this->itemStrength($weapon);
            }
        }

        // Hanna "Dagger Throwing": daggers kept in the spell slots are weapons too
        foreach ($this->getSlotDaggers() as $dagger) {
            $totalDamage += $this->itemStrength($dagger);
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
                    $totalDamage += $this->itemStrength($item);
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

        // Hanna "Dagger Throwing": daggers kept in the spell slots are always used too
        return array_merge($weaponItems, $this->getSlotDaggers());
    }

    /**
     * Hanna "Dagger Throwing": daggers lying in the spell slots, while she can throw them (not cursed).
     *
     * @return list<Item>
     */
    public function getSlotDaggers(): array
    {
        if ($this->getSkills()?->throwsDaggers() !== true) {
            return [];
        }

        $daggers = [];
        foreach ($this->inventory[ItemCategory::SPELL->value] ?? [] as $item) {
            $item = Item::fromAnything($item);
            if ($item->type === ItemType::DAGGER) {
                $daggers[] = $item;
            }
        }

        return $daggers;
    }

    /**
     * Get consumable items (spells).
     * @return array<Item> All consumable items
     */
    public function getConsumableItems(): array
    {
        $consumableItems = [];

        // Collect spells (which are consumable); Hanna's daggers in the spell slots are never consumed
        if (isset($this->inventory[ItemCategory::SPELL->value])) {
            foreach ($this->inventory[ItemCategory::SPELL->value] as $spell) {
                $spell = Item::fromAnything($spell);
                if ($spell->type->getCategory() !== ItemCategory::SPELL) {
                    continue;
                }
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
                if ($spell->type->getCategory() === ItemCategory::SPELL && $spell->type->getDamage() > 0) {
                    $damageItems[] = $spell;
                }
            }
        }

        // Hanna "Dagger Throwing": daggers in the spell slots
        return array_merge($damageItems, $this->getSlotDaggers());
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

    /**
     * The hero card in play: null while the player carries the Curse of Karak.
     * Skill checks use getSkills(), which also covers Lord Karak.
     */
    public function getHero(): ?HeroType
    {
        return $this->cursed ? null : $this->hero;
    }

    /**
     * The skills that apply right now: null while cursed (a cursed hero loses all skills at once).
     * Lord Karak has the skills of the heroes he took over; everyone else those of the chosen hero.
     */
    public function getSkills(): ?HeroSkills
    {
        if ($this->lordKarak) {
            return $this->cursed ? null : new HeroSkills($this->getLordKarakHeroes());
        }
        $hero = $this->getHero();

        return $hero !== null ? HeroSkills::of($hero) : null;
    }

    public function isLordKarak(): bool
    {
        return $this->lordKarak;
    }

    /**
     * @return list<HeroType>
     */
    public function getLordKarakHeroes(): array
    {
        return array_values(array_map(static fn(string $hero): HeroType => HeroType::from($hero), $this->lordKarakHeroes));
    }

    /**
     * The hero picked in the lobby, for display even while cursed.
     */
    public function getChosenHero(): ?HeroType
    {
        return $this->hero;
    }

    public function isCursed(): bool
    {
        return $this->cursed;
    }

    /**
     * Whether one more item of this type fits the inventory (treasures always do).
     */
    public function hasRoomFor(ItemType $type): bool
    {
        $category = $this->getInventoryCategoryForItemType($type);
        $maxItems = $this->getMaxItemsForCategory($category);

        // Hanna "Dagger Throwing": a dagger also fits a free spell slot
        if ($type === ItemType::DAGGER && $this->getSkills()?->throwsDaggers() === true
            && \count($this->inventory[ItemCategory::SPELL->value] ?? []) < self::MAX_SPELLS) {
            return true;
        }

        return $maxItems === null || \count($this->inventory[$category->value] ?? []) < $maxItems;
    }

    public function isAwaitingReincarnation(): bool
    {
        return $this->awaitingReincarnation;
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

        $replacedCategory = null;

        // First, find and remove the existing item
        foreach ($this->inventory as $category => $items) {
            foreach ($items as $index => $item) {
                $item = Item::fromAnything($item);
                if ($item->itemId->equals($itemIdToReplace)) {
                    // Store the old item for event dispatching
                    $oldItem = $item;
                    $replacedCategory = $category;

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

            // Hanna "Dagger Throwing": a dagger that replaces something in the spell slots takes that slot
            if ($command->newItem->type === ItemType::DAGGER && $replacedCategory === ItemCategory::SPELL->value
                && $this->getSkills()?->throwsDaggers() === true) {
                $category = ItemCategory::SPELL;
            }

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
     * Get the external identity of this player, if the client sent one.
     */
    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    /**
     * Get the display name of the player.
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    #[Handler]
    public function getPlayerStatus(GetPlayerStatus $query): array
    {
        return [
            'playerId' => $this->playerId->toString(),
            'hp' => $this->hp,
            'maxHp' => self::MAX_HP,
            'isStunned' => $this->isDefeated(),
            'hero' => $this->getChosenHero()?->value,
            'heroLabel' => $this->getChosenHero()?->label(),
            'awaitingReincarnation' => $this->awaitingReincarnation,
            'cursed' => $this->cursed,
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
     * Set HP for testing purposes (0 to the max HP).
     * @internal Only for use in test mode
     */
    public function setTestHp(int $hp): void
    {
        $this->hp = max(0, min($hp, $this->maxHp));
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
