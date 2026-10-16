<?php

declare(strict_types=1);

namespace App\Tests\Game\Testing;

use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Player\Player;
use App\Game\Testing\TestPackItem;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

#[CoversClass(TestPackItem::class)]
final class TestPackItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ItemType, ItemName}>
     */
    public static function packItems(): iterable
    {
        yield 'key' => ['key', ItemType::KEY, ItemName::SKELETON_TURNKEY];
        yield 'dagger' => ['dagger', ItemType::DAGGER, ItemName::GIANT_RAT];
        yield 'sword' => ['sword', ItemType::SWORD, ItemName::SKELETON_WARRIOR];
        yield 'axe' => ['axe', ItemType::AXE, ItemName::SKELETON_KING];
        yield 'fireball' => ['fireball', ItemType::FIREBALL, ItemName::MUMMY];
        yield 'teleport' => ['teleport', ItemType::TELEPORT, ItemName::GIANT_SPIDER];
        yield 'frost fist' => ['frost_fist', ItemType::FROST_FIST, ItemName::SKELETON_MAGE];
        yield 'dark thorn' => ['dark_thorn', ItemType::DARK_THORN, ItemName::GIANT_BAT];
        yield 'chest' => ['chest', ItemType::CHEST, ItemName::TREASURE_CHEST];
    }

    #[Test]
    #[DataProvider('packItems')]
    public function aPackItemIsTheWonLootOfItsGuard(string $type, ItemType $itemType, ItemName $guard): void
    {
        $item = TestPackItem::create($type);

        self::assertSame($itemType, $item->type);
        self::assertSame($guard, $item->name);
        self::assertTrue($item->guardDefeated);
    }

    #[Test]
    public function aChestCanCarryItsOwnValue(): void
    {
        self::assertSame(3, TestPackItem::create(['type' => 'chest', 'treasureValue' => 3])->treasureValue);
    }

    #[Test]
    public function anUnknownTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TestPackItem::create('banana');
    }

    #[Test]
    public function theTestHpStaysWithinZeroAndTheMaxHp(): void
    {
        [$player] = handle(Player::onPlayerAddedToGame(...), new PlayerAdded(gameId: Uuid::v7(), playerId: Uuid::v7()));
        self::assertInstanceOf(Player::class, $player);

        $player->setTestHp(2);
        self::assertSame(2, $player->getHP());
        $player->setTestHp(9);
        self::assertSame(5, $player->getHP());
        $player->setTestHp(-1);
        self::assertSame(0, $player->getHP());
    }
}
