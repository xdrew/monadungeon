<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Deck\Deck;
use App\Game\Deck\DeckTile;
use App\Game\Deck\GetDeck;
use App\Game\Field\Field;
use App\Game\Field\GetTile;
use App\Game\Field\LordKarakRoomDiscovered;
use App\Game\Field\PlaceTile;
use App\Game\Field\Tile;
use App\Game\Field\TileFeature;
use App\Game\Field\TileOrientation;
use App\Game\Field\TilePlaced;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GameStarted;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Karak: Regent — the Field counts the placed Lord Karak rooms for the Lord Karak variant.
 */
#[CoversClass(Field::class)]
final class LordKarakRoomCountTest extends TestCase
{
    #[Test]
    public function placedArenasAndCursedRoomsAreCounted(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        $tiles = [];
        $tester = MessageBusTester::create(
            static fn(GetCurrentPlayer $_query): Uuid => $playerId,
            static fn(GetCurrentTurn $_query): Uuid => $turnId,
            /** @psalm-suppress InvalidArgument */
            static fn(GetDeck $_query): Deck => Deck::createClassic(new GameCreated($gameId, new \DateTimeImmutable()), startMessageContext()),
            static fn(GetGame $_query): Game => Game::create(new CreateGame($gameId, new \DateTimeImmutable(), regent: true), startMessageContext()),
            static function (GetTile $query) use (&$tiles): Tile {
                return $tiles[$query->tileId->toString()] ?? throw new \RuntimeException('Tile not found');
            },
        );
        [$field] = $tester->handle(Field::create(...), new GameCreated(gameId: $gameId, gameCreateTime: new \DateTimeImmutable()));
        $tester->handle($field->placeFirstTile(...), new GameStarted($gameId, new \DateTimeImmutable()));

        $counts = [];
        foreach ([[TileFeature::CURSED_ROOM], [], [TileFeature::BATTLE_ARENA], [TileFeature::CURSED_ROOM]] as $features) {
            $tileId = Uuid::v7();
            $tile = Tile::fromDeckTile($tileId, DeckTile::create(orientation: TileOrientation::fourSide(), room: false, amount: 1, features: $features));
            $tiles[$tileId->toString()] = $tile;
            $place = $field->getAvailableFieldPlaces()[0];

            [, $messages] = $tester->handle($field->placeTile(...), new PlaceTile($gameId, $tileId, $place, $playerId, $turnId));
            $tester->handle($field->updateAvailableFieldPlaces(...), new TilePlaced($gameId, $tileId, $place, $tile->getOrientation()));

            $discovered = array_values(array_filter($messages, static fn(object $message): bool => $message instanceof LordKarakRoomDiscovered));
            if ($features === []) {
                self::assertSame([], $discovered, 'A plain room is not a Lord Karak room');

                continue;
            }
            self::assertCount(1, $discovered);
            self::assertInstanceOf(LordKarakRoomDiscovered::class, $discovered[0]);
            self::assertTrue($discovered[0]->playerId->equals($playerId));
            self::assertTrue($discovered[0]->position->equals($place));
            $counts[] = $discovered[0]->count;
        }

        self::assertSame([1, 2, 3], $counts);
        self::assertSame(3, $field->getLordKarakRooms());
    }
}
