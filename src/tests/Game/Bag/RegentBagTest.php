<?php

declare(strict_types=1);

namespace App\Tests\Game\Bag;

use App\Game\Bag\Bag;
use App\Game\Deck\DeckCreated;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Player\Player;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Karak: Regent — 6 Giant Bats (Dark Thorn) and 2 Skeleton Mages (Frost Fist) join the bag.
 */
#[CoversClass(Bag::class)]
#[CoversClass(Item::class)]
#[CoversClass(ItemName::class)]
#[CoversClass(ItemType::class)]
final class RegentBagTest extends TestCase
{
    #[Test]
    public function theRegentBagHoldsTheNewTokens(): void
    {
        $items = $this->drawAll(Bag::createClassic(new DeckCreated(Uuid::v7(), roomCount: 100, regent: true)));

        $bats = array_filter($items, static fn(Item $i): bool => $i->name === ItemName::GIANT_BAT);
        $mages = array_filter($items, static fn(Item $i): bool => $i->name === ItemName::SKELETON_MAGE);
        self::assertCount(6, $bats);
        self::assertCount(2, $mages);
        foreach ($bats as $bat) {
            self::assertSame(ItemType::DARK_THORN, $bat->type);
            self::assertSame(6, $bat->guardHP);
        }
        foreach ($mages as $mage) {
            self::assertSame(ItemType::FROST_FIST, $mage->type);
            self::assertSame(11, $mage->guardHP);
        }
        self::assertCount(1, array_filter($items, static fn(Item $i): bool => $i->name === ItemName::DRAGON));
    }

    #[Test]
    public function theBaseBagHasNoRegentTokens(): void
    {
        $items = $this->drawAll(Bag::createClassic(new DeckCreated(Uuid::v7(), roomCount: 100)));

        self::assertSame([], array_values(array_filter(
            $items,
            static fn(Item $i): bool => \in_array($i->name, [ItemName::GIANT_BAT, ItemName::SKELETON_MAGE], true),
        )));
    }

    #[Test]
    public function theNewSpellsAndTheDuelTarget(): void
    {
        self::assertSame(ItemCategory::SPELL, ItemType::FROST_FIST->getCategory());
        self::assertSame(2, ItemType::FROST_FIST->getDamage());
        self::assertTrue(ItemType::FROST_FIST->isDisposable());
        self::assertSame(ItemCategory::SPELL, ItemType::DARK_THORN->getCategory());
        self::assertSame(0, ItemType::DARK_THORN->getDamage());

        $target = Item::createDuelTarget(9);
        self::assertSame(ItemName::HERO, $target->name);
        self::assertSame(9, $target->guardHP);
        self::assertSame(0, $target->treasureValue);
        self::assertFalse($target->endsGame);

        self::assertSame(ItemName::GIANT_BAT, Item::createFromName('giant_bat')->name);
        self::assertSame(ItemName::SKELETON_MAGE, Item::createFromName('skeleton_mage')->name);

        for ($i = 0; $i < 300; ++$i) {
            self::assertNotContains(
                ItemName::getRandom(),
                [ItemName::DRAGON, ItemName::HERO, ItemName::GIANT_BAT, ItemName::SKELETON_MAGE],
                'Random fill tokens are base monsters only',
            );
        }
    }

    #[Test]
    public function aPlayerHasRoomForAnItemWhileTheCategoryIsNotFull(): void
    {
        $player = Player::onPlayerAddedToGame(new PlayerAdded(Uuid::v7(), Uuid::v7()));
        $player->setTestInventory(['mummy', 'giant_spider', 'giant_bat']); // three spells

        self::assertFalse($player->hasRoomFor(ItemType::FROST_FIST));
        self::assertTrue($player->hasRoomFor(ItemType::CHEST), 'Treasures always fit');
        self::assertTrue($player->hasRoomFor(ItemType::AXE));
    }

    /**
     * @return list<Item>
     */
    private function drawAll(Bag $bag): array
    {
        $items = [];

        while ($bag->count() > 0) {
            $items[] = $bag->getNextItem();
        }

        return $items;
    }
}
