<?php

declare(strict_types=1);

namespace App\Tests\Game\Bag;

use App\Game\Bag\Bag;
use App\Game\Battle\BattleResult;
use App\Game\Bag\Error\NoItemsLeftInBag;
use App\Game\Bag\GetBag;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Deck\DeckCreated;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

#[CoversClass(Bag::class)]
final class BagTest extends TestCase
{
    private Uuid $gameId;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
    }

    #[Test]
    public function itCreatesClassicBag(): void
    {
        $deckCreated = new DeckCreated(
            gameId: $this->gameId,
            roomCount: 88, // Classic rulebook count
        );

        [$bag, $messages] = handle(Bag::createClassic(...), $deckCreated);

        self::assertInstanceOf(Bag::class, $bag);
        self::assertEquals([], $messages);

        // Test that we can get items from the bag
        $itemCount = 0;
        try {
            while (true) {
                $item = $bag->getNextItem();
                self::assertInstanceOf(Item::class, $item);
                $itemCount++;
                
                // Prevent infinite loop in case of test failure
                if ($itemCount > 200) {
                    break;
                }
            }
        } catch (NoItemsLeftInBag $e) {
            // This is expected when bag is empty
        }

        // Classic bag should have exactly 88 items total
        // (roomCount - 1) items adjusted to fit + dragon = 88 total
        self::assertEquals(88, $itemCount);
    }

    #[Test]
    public function itCreatesTestBag(): void
    {
        $deckCreated = new DeckCreated(
            gameId: $this->gameId,
            roomCount: 88,
        );

        [$bag, $messages] = handle(Bag::createForTest(...), $deckCreated);

        self::assertInstanceOf(Bag::class, $bag);
        self::assertEquals([], $messages);

        // Test bag should have specific item counts
        $itemCount = 0;
        try {
            while (true) {
                $item = $bag->getNextItem();
                self::assertInstanceOf(Item::class, $item);
                $itemCount++;
                
                // Prevent infinite loop
                if ($itemCount > 200) {
                    break;
                }
            }
        } catch (NoItemsLeftInBag $e) {
            // Expected when bag is empty
        }

        // Test bag: 1 giant rat + (7 * 4) giant spiders + 4 skeleton turnkeys + 4 treasure chests = 37 items
        // 1 giant rat, 28 giant spiders, 4 skeleton turnkeys, 4 treasure chests
        self::assertEquals(37, $itemCount);
    }

    #[Test]
    public function itCreatesClassicBagWithCorrectItemDistribution(): void
    {
        $deckCreated = new DeckCreated(
            gameId: $this->gameId,
            roomCount: 88,
        );

        [$bag, ] = handle(Bag::createClassic(...), $deckCreated);

        // Count different item types
        $itemTypeCounts = [];
        try {
            while (true) {
                $item = $bag->getNextItem();
                $typeName = $item->type->value;
                $itemTypeCounts[$typeName] = ($itemTypeCounts[$typeName] ?? 0) + 1;
            }
        } catch (NoItemsLeftInBag $e) {
            // Expected
        }

        // Verify we have the dragon (should always be present)
        self::assertArrayHasKey('ruby_chest', $itemTypeCounts);
        self::assertGreaterThanOrEqual(1, $itemTypeCounts['ruby_chest']);

        // Should have various item types represented
        self::assertGreaterThan(3, count($itemTypeCounts));
    }

    #[Test]
    public function itGetsNextItem(): void
    {
        $deckCreated = new DeckCreated(
            gameId: $this->gameId,
            roomCount: 10, // Small bag for testing
        );

        [$bag, ] = handle(Bag::createClassic(...), $deckCreated);

        $item = $bag->getNextItem();
        self::assertInstanceOf(Item::class, $item);
        self::assertInstanceOf(ItemType::class, $item->type);
        self::assertInstanceOf(ItemName::class, $item->name);
    }

    #[Test]
    public function itThrowsExceptionWhenBagIsEmpty(): void
    {
        $deckCreated = new DeckCreated(
            gameId: $this->gameId,
            roomCount: 1, // Minimal bag - should be empty after creating
        );

        [$bag, ] = handle(Bag::createClassic(...), $deckCreated);

        // Empty the bag
        try {
            while (true) {
                $bag->getNextItem();
            }
        } catch (NoItemsLeftInBag $e) {
            // Expected - bag is now empty
        }

        // Try to get another item from empty bag
        $this->expectException(NoItemsLeftInBag::class);
        $bag->getNextItem();
    }

    #[Test]
    public function itReturnsInstanceViaGetBagQuery(): void
    {
        $deckCreated = new DeckCreated(
            gameId: $this->gameId,
            roomCount: 10,
        );

        [$bag, ] = handle(Bag::createClassic(...), $deckCreated);

        $getBag = new GetBag($this->gameId);
        $bagInstance = $bag->getInstance($getBag);

        self::assertSame($bag, $bagInstance);
    }

    #[Test]
    public function itCreatesItemWithDragonFactory(): void
    {
        $item = Item::createDragon();

        self::assertEquals(ItemName::DRAGON, $item->name);
        self::assertEquals(ItemType::RUBY_CHEST, $item->type);
        self::assertEquals(15, $item->guardHP); // Dragon has 15 HP
        self::assertEquals(3, $item->treasureValue); // Ruby chest value
        self::assertTrue($item->type->endsGame());
    }

    #[Test]
    public function itCreatesItemWithFallenFactory(): void
    {
        $item = Item::createFallen();

        self::assertEquals(ItemName::FALLEN, $item->name);
        self::assertEquals(ItemType::CHEST, $item->type);
        self::assertEquals(12, $item->guardHP); // Fallen has 12 HP
        self::assertEquals(2, $item->treasureValue); // Chest value
    }

    #[Test]
    public function itCreatesItemWithSkeletonKingFactory(): void
    {
        $item = Item::createSkeletonKing();

        self::assertEquals(ItemName::SKELETON_KING, $item->name);
        self::assertEquals(ItemType::AXE, $item->type);
        self::assertEquals(10, $item->guardHP); // Skeleton King has 10 HP
        self::assertEquals(3, $item->type->getDamage()); // Axe damage
    }

    #[Test]
    public function itCreatesItemWithSkeletonWarriorFactory(): void
    {
        $item = Item::createSkeletonWarrior();

        self::assertEquals(ItemName::SKELETON_WARRIOR, $item->name);
        self::assertEquals(ItemType::SWORD, $item->type);
        self::assertEquals(9, $item->guardHP); // Skeleton Warrior has 9 HP
        self::assertEquals(2, $item->type->getDamage()); // Sword damage
    }

    #[Test]
    public function itCreatesItemWithGiantRatFactory(): void
    {
        $item = Item::createGiantRat();

        self::assertEquals(ItemName::GIANT_RAT, $item->name);
        self::assertEquals(ItemType::DAGGER, $item->type);
        self::assertEquals(5, $item->guardHP); // Giant Rat has 5 HP
        self::assertEquals(1, $item->type->getDamage()); // Dagger damage
    }

    #[Test]
    public function itCreatesItemWithGiantSpiderFactory(): void
    {
        $item = Item::createGiantSpider();

        self::assertEquals(ItemName::GIANT_SPIDER, $item->name);
        self::assertEquals(ItemType::TELEPORT, $item->type);
        self::assertEquals(6, $item->guardHP); // Giant Spider has 6 HP
        self::assertEquals(0, $item->type->getDamage()); // Teleport has no damage
    }

    #[Test]
    public function itCreatesItemWithMummyFactory(): void
    {
        $item = Item::createMummy();

        self::assertEquals(ItemName::MUMMY, $item->name);
        self::assertEquals(ItemType::FIREBALL, $item->type);
        self::assertEquals(7, $item->guardHP); // Mummy has 7 HP
        self::assertEquals(1, $item->type->getDamage()); // Fireball damage
    }

    #[Test]
    public function itCreatesItemWithSkeletonTurnkeyFactory(): void
    {
        $item = Item::createSkeletonTurnkey();

        self::assertEquals(ItemName::SKELETON_TURNKEY, $item->name);
        self::assertEquals(ItemType::KEY, $item->type);
        self::assertEquals(8, $item->guardHP); // Skeleton Turnkey has 8 HP
        self::assertEquals(0, $item->type->getDamage()); // Key has no damage
    }

    #[Test]
    public function itCreatesItemWithTreasureChestFactory(): void
    {
        $item = Item::createTreasureChest();

        self::assertEquals(ItemName::TREASURE_CHEST, $item->name);
        self::assertEquals(ItemType::CHEST, $item->type);
        self::assertEquals(0, $item->guardHP); // Treasure chest has no guard
        self::assertEquals(2, $item->treasureValue); // Chest value
    }

    #[Test]
    public function itCreatesRandomItem(): void
    {
        $item = Item::createRandom();

        self::assertInstanceOf(ItemName::class, $item->name);
        self::assertInstanceOf(ItemType::class, $item->type);
        self::assertGreaterThanOrEqual(0, $item->guardHP); // Random can include TREASURE_CHEST (0 HP)
        self::assertLessThanOrEqual(12, $item->guardHP); // Random can include FALLEN (12 HP)
    }

    #[Test]
    public function itHandlesBattleWithWin(): void
    {
        $item = Item::createGiantRat(); // 5 HP

        $result = $item->attack(6); // More damage than HP

        self::assertEquals(BattleResult::WIN, $result);
        self::assertFalse($item->guardDefeated); // attack() doesn't modify guardDefeated
    }

    #[Test]
    public function itHandlesBattleWithDraw(): void
    {
        $item = Item::createGiantRat(); // 5 HP

        $result = $item->attack(5); // Exact damage

        self::assertEquals(BattleResult::DRAW, $result);
        self::assertFalse($item->guardDefeated);
    }

    #[Test]
    public function itHandlesBattleWithLoss(): void
    {
        $item = Item::createGiantRat(); // 5 HP

        $result = $item->attack(3); // Less damage than HP

        self::assertEquals(BattleResult::LOSE, $result);
        self::assertFalse($item->guardDefeated);
    }

    #[Test]
    public function itChecksIfItemIsLocked(): void
    {
        // Item with guard
        $guardedItem = Item::createSkeletonWarrior();
        self::assertTrue($guardedItem->isLocked());

        // Item with guard defeated
        $defeatedItem = Item::createSkeletonWarrior()->defeatMonster();
        self::assertFalse($defeatedItem->isLocked());

        // Item with no guard (treasure chest)
        $treasureChest = Item::createTreasureChest();
        self::assertTrue($treasureChest->isLocked()); // No guard means locked
    }

    #[Test]
    public function itCreatesItemFromArray(): void
    {
        $itemData = [
            'name' => ItemName::SKELETON_WARRIOR->value,
            'type' => ItemType::SWORD->value,
            'guardHP' => 9,
            'treasureValue' => 0,
            'guardDefeated' => false,
            'itemId' => Uuid::v7()->toString(),
        ];

        $item = Item::fromArray($itemData);

        self::assertEquals(ItemName::SKELETON_WARRIOR, $item->name);
        self::assertEquals(ItemType::SWORD, $item->type);
        self::assertEquals(9, $item->guardHP);
        self::assertEquals(0, $item->treasureValue);
        self::assertFalse($item->guardDefeated);
    }

    #[Test]
    public function itCreatesItemFromExistingItem(): void
    {
        $originalItem = Item::createDragon();
        $copiedItem = Item::fromAnything($originalItem);

        self::assertSame($originalItem, $copiedItem);
    }

    #[Test]
    public function itTestsItemTypeEnumMethods(): void
    {
        // Test damage values
        self::assertEquals(1, ItemType::DAGGER->getDamage());
        self::assertEquals(2, ItemType::SWORD->getDamage());
        self::assertEquals(3, ItemType::AXE->getDamage());
        self::assertEquals(1, ItemType::FIREBALL->getDamage());
        self::assertEquals(0, ItemType::KEY->getDamage());

        // Test treasure values
        self::assertEquals(2, ItemType::CHEST->getTreasureValue());
        self::assertEquals(3, ItemType::RUBY_CHEST->getTreasureValue());
        self::assertEquals(0, ItemType::SWORD->getTreasureValue());

        // Test categories
        self::assertEquals(ItemCategory::WEAPON, ItemType::SWORD->getCategory());
        self::assertEquals(ItemCategory::SPELL, ItemType::FIREBALL->getCategory());
        self::assertEquals(ItemCategory::KEY, ItemType::KEY->getCategory());
        self::assertEquals(ItemCategory::TREASURE, ItemType::CHEST->getCategory());

        // Test disposable items
        self::assertTrue(ItemType::FIREBALL->isDisposable());
        self::assertFalse(ItemType::SWORD->isDisposable());

        // Test game ending items
        self::assertTrue(ItemType::RUBY_CHEST->endsGame());
        self::assertFalse(ItemType::CHEST->endsGame());
    }

    #[Test]
    public function itTestsItemTypeRandomMethods(): void
    {
        $randomWeapon = ItemType::getRandomWeapon();
        self::assertContains($randomWeapon, [ItemType::DAGGER, ItemType::SWORD, ItemType::AXE]);

        $randomSpell = ItemType::getRandomSpell();
        self::assertContains($randomSpell, [ItemType::FIREBALL, ItemType::TELEPORT]);

        $randomItem = ItemType::getRandom();
        self::assertInstanceOf(ItemType::class, $randomItem);
        self::assertNotEquals(ItemType::RUBY_CHEST, $randomItem); // Ruby chest excluded from random
    }

    #[Test]
    public function itTestsItemNameHpValues(): void
    {
        self::assertEquals(15, ItemName::DRAGON->getHp());
        self::assertEquals(12, ItemName::FALLEN->getHp());
        self::assertEquals(10, ItemName::SKELETON_KING->getHp());
        self::assertEquals(9, ItemName::SKELETON_WARRIOR->getHp());
        self::assertEquals(5, ItemName::GIANT_RAT->getHp());
        self::assertEquals(6, ItemName::GIANT_SPIDER->getHp());
        self::assertEquals(7, ItemName::MUMMY->getHp());
        self::assertEquals(8, ItemName::SKELETON_TURNKEY->getHp());
        self::assertEquals(0, ItemName::TREASURE_CHEST->getHp());
        
        $randomHP = ItemName::getRandom()->getHp();
        self::assertGreaterThanOrEqual(0, $randomHP); // TREASURE_CHEST has 0
        self::assertLessThanOrEqual(12, $randomHP); // FALLEN has 12
    }

    #[Test]
    public function itTestsItemCategoryRandom(): void
    {
        $randomCategory = ItemCategory::getRandom();
        self::assertInstanceOf(ItemCategory::class, $randomCategory);
        self::assertContains($randomCategory, ItemCategory::cases());
    }

    #[Test]
    public function itTestsBattleResultEnum(): void
    {
        self::assertEquals('win', BattleResult::WIN->value);
        self::assertEquals('lose', BattleResult::LOSE->value);
        self::assertEquals('draw', BattleResult::DRAW->value);
    }
}