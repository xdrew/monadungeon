<?php

declare(strict_types=1);

namespace App\Tests\Game\Player;

use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Player\AddItemToInventory;
use App\Game\Player\CharacterPicked;
use App\Game\Player\Error\InventoryFullException;
use App\Game\Player\GetPlayer;
use App\Game\Player\GetPlayerStatus;
use App\Game\Player\GetReady;
use App\Game\Player\ItemAddedToInventory;
use App\Game\Player\ItemPickupSkipped;
use App\Game\Player\ItemRemovedFromInventory;
use App\Game\Player\ItemReplacedInInventory;
use App\Game\Player\PickCharacter;
use App\Game\Player\Player;
use App\Game\Player\PlayerReady;
use App\Game\Player\PlayerStunned;
use App\Game\Player\QueryPlayerInventory;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Player\ReplaceInventoryItem;
use App\Game\Player\ResetPlayerHP;
use App\Game\Player\SkipItemPickup;
use App\Game\Turn\Error\NotYourTurnException;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

#[CoversClass(Player::class)]
final class PlayerTest extends TestCase
{
    private Uuid $gameId;
    private Uuid $playerId;
    private Uuid $characterId;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->characterId = Uuid::v7();
    }

    #[Test]
    public function itCreatesPlayerFromPlayerAddedEvent(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );

        [$player, $messages] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        self::assertInstanceOf(Player::class, $player);
        self::assertEquals($this->playerId, $player->getPlayerId());
        self::assertEquals($this->gameId, $player->getGameId());
        self::assertEquals(5, $player->getHP());
        self::assertFalse($player->isReady());
        self::assertNull($player->getCharacterId());
        self::assertFalse($player->isDefeated());
        self::assertEquals([], $messages);
    }

    #[Test]
    public function itPicksCharacter(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $pickCharacter = new PickCharacter(
            gameId: $this->gameId,
            playerId: $this->playerId,
            characterId: $this->characterId,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($player->pickCharacter(...), $pickCharacter);

        self::assertEquals($this->characterId, $player->getCharacterId());
        self::assertEquals(
            [new CharacterPicked($this->gameId, $this->playerId, $this->characterId)],
            $messages,
        );
    }

    #[Test]
    public function itGetsReady(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $getReady = new GetReady(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($player->getReady(...), $getReady);

        self::assertTrue($player->isReady());
        self::assertEquals(
            [new PlayerReady($this->playerId, $this->gameId)],
            $messages,
        );
    }

    #[Test]
    public function itReducesPlayerHP(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $reduceHP = new ReducePlayerHP(
            gameId: $this->gameId,
            playerId: $this->playerId,
            amount: 5,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($player->reduceHP(...), $reduceHP);

        self::assertEquals(0, $player->getHP());
        self::assertTrue($player->isDefeated());
        self::assertCount(1, $messages);
        self::assertInstanceOf(PlayerStunned::class, $messages[0]);
        self::assertEquals($this->gameId, $messages[0]->gameId);
        self::assertEquals($this->playerId, $messages[0]->playerId);
    }

    #[Test]
    public function itReducesPlayerHPWithoutStunningWhenNotGoingToZero(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
            hp: 3,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $reduceHP = new ReducePlayerHP(
            playerId: $this->playerId,
            gameId: $this->gameId,
            amount: 1,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($player->reduceHP(...), $reduceHP);

        self::assertEquals(2, $player->getHP());
        self::assertFalse($player->isDefeated());
        self::assertEquals([], $messages);
    }

    #[Test]
    public function itResetsPlayerHP(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        // First reduce HP to 0
        $reduceHP = new ReducePlayerHP(
            gameId: $this->gameId,
            playerId: $this->playerId,
            amount: 5,
        );
        $tester = MessageBusTester::create();
        $tester->handle($player->reduceHP(...), $reduceHP);
        
        self::assertEquals(0, $player->getHP());
        self::assertTrue($player->isDefeated());

        // Now reset HP
        $resetHP = new ResetPlayerHP(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [, $messages] = $tester->handle($player->resetHP(...), $resetHP);

        self::assertEquals(5, $player->getHP());
        self::assertFalse($player->isDefeated());
        self::assertEquals([], $messages);
    }

    #[Test]
    public function itAddsItemToInventory(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $item = new Item(
            name: ItemName::SKELETON_WARRIOR,
            type: ItemType::SWORD,
        );
        $addItem = new AddItemToInventory(
            gameId: $this->gameId,
            playerId: $this->playerId,
            item: $item,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($player->addItem(...), $addItem);

        $inventory = $player->getInventory();
        self::assertCount(1, $inventory[ItemCategory::WEAPON->value]);
        self::assertEquals($item, $inventory[ItemCategory::WEAPON->value][0]);
        self::assertEquals(
            [new ItemAddedToInventory($this->gameId, $this->playerId, $item)],
            $messages,
        );
    }

    #[Test]
    public function itThrowsExceptionWhenInventoryIsFull(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        // Fill up weapons inventory (max 2)
        $weapon1 = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        $weapon2 = new Item(ItemName::SKELETON_KING, ItemType::AXE);
        $weapon3 = new Item(ItemName::GIANT_RAT, ItemType::DAGGER);

        $tester = MessageBusTester::create();
        
        // Add first two weapons
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon1));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon2));

        // Try to add third weapon - should throw exception
        $this->expectException(InventoryFullException::class);
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon3));
    }

    #[Test]
    public function itRemovesItemFromInventory(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $item = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        $tester = MessageBusTester::create();
        
        // Add item first
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $item));
        
        // Remove item
        $removeItem = new RemoveItemFromInventory(
            gameId: $this->gameId,
            playerId: $this->playerId,
            itemId: $item->itemId,
        );
        [, $messages] = $tester->handle($player->removeItem(...), $removeItem);

        $inventory = $player->getInventory();
        self::assertCount(0, $inventory[ItemCategory::WEAPON->value]);
        self::assertEquals(
            [new ItemRemovedFromInventory($this->gameId, $this->playerId, $item)],
            $messages,
        );
    }

    #[Test]
    public function itReplacesInventoryItem(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $oldItem = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        $newItem = new Item(ItemName::SKELETON_KING, ItemType::AXE);
        
        $tester = MessageBusTester::create();
        
        // Add old item first
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $oldItem));
        
        // Replace item
        $replaceItem = new ReplaceInventoryItem(
            playerId: $this->playerId,
            gameId: $this->gameId,
            itemIdToReplace: $oldItem->itemId,
            newItem: $newItem,
        );
        [, $messages] = $tester->handle($player->replaceInventoryItem(...), $replaceItem);

        $inventory = $player->getInventory();
        self::assertCount(1, $inventory[ItemCategory::WEAPON->value]);
        self::assertEquals($newItem, $inventory[ItemCategory::WEAPON->value][0]);
        
        self::assertCount(2, $messages);
        self::assertInstanceOf(ItemRemovedFromInventory::class, $messages[0]);
        self::assertInstanceOf(ItemReplacedInInventory::class, $messages[1]);
    }

    #[Test]
    public function itSkipsItemPickup(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $item = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        $skipPickup = new SkipItemPickup(
            playerId: $this->playerId,
            gameId: $this->gameId,
            skippedItem: $item,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($player->skipItemPickup(...), $skipPickup);

        self::assertEquals(
            [new ItemPickupSkipped($this->gameId, $this->playerId, $item)],
            $messages,
        );
    }

    #[Test]
    public function itCalculatesItemDamage(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD); // 2 damage
        $spell = new Item(ItemName::MUMMY, ItemType::FIREBALL); // 1 damage
        
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell));

        $totalDamage = $player->calculateItemDamage();
        self::assertEquals(3, $totalDamage); // 2 + 1
    }

    #[Test]
    public function itCalculatesWeaponDamage(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD); // 2 damage
        $spell = new Item(ItemName::MUMMY, ItemType::FIREBALL); // 1 damage
        
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell));

        $weaponDamage = $player->calculateWeaponDamage();
        self::assertEquals(2, $weaponDamage); // Only weapon damage, not spell
    }

    #[Test]
    public function itCalculatesDamageFromSpecificItems(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD); // 2 damage
        $spell = new Item(ItemName::MUMMY, ItemType::FIREBALL); // 1 damage
        
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell));

        // Calculate damage from only the spell
        $damage = $player->calculateDamageFromItems([$spell->itemId->toString()]);
        self::assertEquals(1, $damage);
    }

    #[Test]
    public function itGetsWeaponItems(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        $spell = new Item(ItemName::MUMMY, ItemType::FIREBALL);
        
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell));

        $weapons = $player->getWeaponItems();
        self::assertCount(1, $weapons);
        self::assertEquals($weapon, $weapons[0]);
    }

    #[Test]
    public function itGetsConsumableItems(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        $spell = new Item(ItemName::MUMMY, ItemType::FIREBALL);
        
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell));

        $consumables = $player->getConsumableItems();
        self::assertCount(1, $consumables);
        self::assertEquals($spell, $consumables[0]);
    }

    #[Test]
    public function itGetsDamageItems(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD); // 2 damage
        $spell = new Item(ItemName::MUMMY, ItemType::FIREBALL); // 1 damage
        $key = new Item(ItemName::SKELETON_TURNKEY, ItemType::KEY); // 0 damage
        
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $key));

        $damageItems = $player->getDamageItems();
        self::assertCount(2, $damageItems); // Only weapon and spell, not key
        self::assertContains($weapon, $damageItems);
        self::assertContains($spell, $damageItems);
    }

    #[Test]
    public function itGetsItemsByIds(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        $spell = new Item(ItemName::MUMMY, ItemType::FIREBALL);
        
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell));

        $items = $player->getItemsByIds([$weapon->itemId->toString()]);
        self::assertCount(1, $items);
        self::assertEquals($weapon, $items[0]);
    }

    #[Test]
    public function itChecksIfPlayerHasItemOfType(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));

        self::assertTrue($player->hasItemOfType(ItemType::SWORD));
        self::assertFalse($player->hasItemOfType(ItemType::KEY));
    }

    #[Test]
    public function itGetsAllItems(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        $spell = new Item(ItemName::MUMMY, ItemType::FIREBALL);
        $key = new Item(ItemName::SKELETON_TURNKEY, ItemType::KEY);
        
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $key));

        $allItems = $player->getAllItems();
        self::assertCount(3, $allItems);
        self::assertContains($weapon, $allItems);
        self::assertContains($spell, $allItems);
        self::assertContains($key, $allItems);
    }

    #[Test]
    public function itValidatesPlayerTurn(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $playerId = $this->playerId;
        $tester = MessageBusTester::create(
            static function (GetCurrentPlayer $query) use ($playerId): Uuid {
                return $playerId;
            }
        );

        // Should not throw exception when it's player's turn
        $tester->handle(
            fn($query, $context) => $player->ensureIsPlayerTurn($context),
            new GetCurrentPlayer($this->gameId)
        );
        
        // Test that it throws exception when it's not player's turn  
        $otherPlayerId = Uuid::v7();
        $tester2 = MessageBusTester::create(
            static fn (GetCurrentPlayer $query): Uuid => $otherPlayerId,
        );
        
        $this->expectException(NotYourTurnException::class);
        $tester2->handle(
            fn($query, $context) => $player->ensureIsPlayerTurn($context),
            new GetCurrentPlayer($this->gameId)
        );
    }

    #[Test]
    public function itReturnsPlayerInventoryViaQuery(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $weapon = new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD);
        $tester = MessageBusTester::create();
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $weapon));

        $query = new QueryPlayerInventory($this->gameId, $this->playerId);
        $inventory = $player->getPlayerInventory($query);

        self::assertIsArray($inventory);
        self::assertArrayHasKey(ItemCategory::WEAPON->value, $inventory);
        self::assertCount(1, $inventory[ItemCategory::WEAPON->value]);
    }

    #[Test]
    public function itReturnsPlayerInstanceViaQuery(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $query = new GetPlayer($this->gameId, $this->playerId);
        $playerInstance = $player->getPlayerInstance($query);

        self::assertSame($player, $playerInstance);
    }

    #[Test]
    public function itRespectsInventoryLimitsForKeys(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $tester = MessageBusTester::create();

        // Test key limit (max 1)
        $key1 = new Item(ItemName::SKELETON_TURNKEY, ItemType::KEY);
        $key2 = new Item(ItemName::SKELETON_TURNKEY, ItemType::KEY);
        
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $key1));
        $this->expectException(InventoryFullException::class);
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $key2));
    }

    #[Test]
    public function itRespectsInventoryLimitsForSpells(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $tester = MessageBusTester::create();

        // Test spell limit (max 3)
        $spell1 = new Item(ItemName::MUMMY, ItemType::FIREBALL);
        $spell2 = new Item(ItemName::GIANT_SPIDER, ItemType::TELEPORT);
        $spell3 = new Item(ItemName::MUMMY, ItemType::FIREBALL);
        $spell4 = new Item(ItemName::GIANT_SPIDER, ItemType::TELEPORT);
        
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell1));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell2));
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell3));
        
        $this->expectException(InventoryFullException::class);
        $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $spell4));
    }

    #[Test]
    public function itAllowsUnlimitedTreasures(): void
    {
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
        );
        [$player, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $tester = MessageBusTester::create();

        // Add multiple treasures - should not throw exception
        for ($i = 0; $i < 10; $i++) {
            $treasure = new Item(ItemName::TREASURE_CHEST, ItemType::CHEST);
            $tester->handle($player->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $treasure));
        }

        $inventory = $player->getInventory();
        self::assertCount(10, $inventory[ItemCategory::TREASURE->value]);
    }
}