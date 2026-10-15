<?php

declare(strict_types=1);

namespace App\Tests\Game\Deck;

use App\Game\Deck\Deck;
use App\Game\Deck\DeckCreated;
use App\Game\Deck\DeckTile;
use App\Game\Field\TileFeature;
use App\Game\GameLifecycle\GameCreated;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Karak: Regent — the expansion adds 18 tiles (6 Battle Arenas, 4 Cursed Rooms, 8 rooms) on top of the base deck.
 */
#[CoversClass(Deck::class)]
#[CoversClass(DeckTile::class)]
final class RegentDeckTest extends TestCase
{
    #[Test]
    public function theRegentDeckHoldsEveryExpansionTile(): void
    {
        [$deck, $messages] = handle(Deck::createClassic(...), new GameCreated(Uuid::v7(), new \DateTimeImmutable(), deckSize: 88, regent: true));

        self::assertSame(107, $deck->getTilesTotalCount(), '88 base tiles + 18 Regent tiles + the start tile');
        $created = $messages[0] ?? null;
        self::assertInstanceOf(DeckCreated::class, $created);
        self::assertTrue($created->regent);

        $tiles = $this->drawAll($deck);
        self::assertSame([TileFeature::HEALING_FOUNTAIN], $tiles[0]->features, 'The start tile still comes first');

        $arenas = array_filter($tiles, static fn(DeckTile $t): bool => \in_array(TileFeature::BATTLE_ARENA, $t->features, true));
        $cursed = array_filter($tiles, static fn(DeckTile $t): bool => \in_array(TileFeature::CURSED_ROOM, $t->features, true));
        self::assertCount(6, $arenas);
        self::assertCount(4, $cursed);
        foreach ([...$arenas, ...$cursed] as $tile) {
            self::assertFalse($tile->room, 'Lord Karak rooms are corridors: no token is drawn on them');
        }
        foreach ($arenas as $arena) {
            self::assertSame(2, $this->openSides($arena), 'A Battle Arena is a straight two-sided tile');
        }
        foreach ($cursed as $room) {
            self::assertSame(4, $this->openSides($room), 'A Cursed Room is open on all four sides');
        }

        $rooms = \count(array_filter($tiles, static fn(DeckTile $t): bool => $t->room));
        self::assertSame($rooms, $deck->getRoomCount());
        self::assertSame($rooms, $created->roomCount);
    }

    #[Test]
    public function theBaseDeckHasNoRegentTiles(): void
    {
        [$deck, $messages] = handle(Deck::createClassic(...), new GameCreated(Uuid::v7(), new \DateTimeImmutable(), deckSize: 88));

        self::assertSame(89, $deck->getTilesTotalCount());
        $created = $messages[0] ?? null;
        self::assertInstanceOf(DeckCreated::class, $created);
        self::assertFalse($created->regent);
        foreach ($this->drawAll($deck) as $tile) {
            self::assertNotContains(TileFeature::BATTLE_ARENA, $tile->features);
            self::assertNotContains(TileFeature::CURSED_ROOM, $tile->features);
        }
    }

    #[Test]
    public function scriptedDecksNameTheLordKarakRooms(): void
    {
        $arena = DeckTile::createFromName('battleArena');
        self::assertSame([TileFeature::BATTLE_ARENA], $arena->features);
        self::assertFalse($arena->room);
        self::assertSame(2, $this->openSides($arena));

        $cursed = DeckTile::createFromName('cursedRoom');
        self::assertSame([TileFeature::CURSED_ROOM], $cursed->features);
        self::assertFalse($cursed->room);
        self::assertSame(4, $this->openSides($cursed));
    }

    /**
     * @return list<DeckTile>
     */
    private function drawAll(Deck $deck): array
    {
        $tiles = [];

        while (!$deck->isEmpty()) {
            $tiles[] = $deck->getNextTile();
        }

        return $tiles;
    }

    private function openSides(DeckTile $tile): int
    {
        return \count(array_filter($tile->orientation->getOrientation()));
    }
}
