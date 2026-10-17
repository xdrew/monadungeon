<?php

declare(strict_types=1);

namespace App\Tests\Game\Movement;

use App\Game\Battle\StartBattle;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GameStarted;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Movement\Commands\MovePlayer;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\Exception\InvalidMovementException;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Movement\Movement;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Turn\GameTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnAction;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Wizard "Astral Walking": a normal move onto an adjacent placed tile, door or not.
 *
 * Dungeon: 0,0 and 1,0 are connected by a door; 0,1 is placed next to 0,0 but behind a wall.
 */
#[CoversClass(Movement::class)]
final class AstralWalkingTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    private Movement $movement;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->movement = Movement::create(new GameCreated($this->gameId, new \DateTimeImmutable()));

        $players = [$this->playerId];
        MessageBusTester::create(
            static fn(GetGame $_query): object => new class ($players) {
                /** @param list<Uuid> $players */
                public function __construct(private readonly array $players) {}

                /** @return list<Uuid> */
                public function getPlayers(): array
                {
                    return $this->players;
                }
            },
        )->handle($this->movement->initializeStartingPositions(...), new GameStarted($this->gameId, new \DateTimeImmutable(), $players));
    }

    #[Test]
    public function wizardWalksThroughAWallOntoAPlacedTile(): void
    {
        $tester = $this->createTester(HeroType::WIZARD);

        [, $messages] = $tester->handle($this->movement->movePlayer(...), $this->move('0,1'));

        self::assertSame('0,1', $this->position());
        $moved = $this->findMessage($messages, PlayerMoved::class);
        self::assertInstanceOf(PlayerMoved::class, $moved);
        self::assertSame('0,1', $moved->toPosition->toString());

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::MOVE, $action->action, 'A wall pass is an ordinary move: it costs 1 move');
        self::assertFalse($action->deferTurnEnd);
        self::assertTrue($action->additionalData['throughWall'] ?? false);
        self::assertSame('astral_walking', $action->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function aMoveThroughADoorIsNotAnAstralWalk(): void
    {
        $tester = $this->createTester(HeroType::WIZARD);

        [, $messages] = $tester->handle($this->movement->movePlayer(...), $this->move('1,0'));

        self::assertSame('1,0', $this->position());
        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertArrayNotHasKey('throughWall', $action->additionalData);
        self::assertNull($action->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function wizardCannotWalkDiagonallyOrOntoUndiscoveredPlaces(): void
    {
        $tester = $this->createTester(HeroType::WIZARD);

        foreach (['1,1', '-1,0'] as $target) {
            try {
                $tester->handle($this->movement->movePlayer(...), $this->move($target));
                self::fail('Expected InvalidMovementException for ' . $target);
            } catch (InvalidMovementException $exception) {
                self::assertStringContainsString('no valid transition', $exception->getMessage(), $target);
            }
            self::assertSame('0,0', $this->position(), 'Position stays untouched');
        }
    }

    #[Test]
    public function otherHeroesStillNeedADoor(): void
    {
        $tester = $this->createTester(HeroType::THIEF);

        $this->expectException(InvalidMovementException::class);
        $this->expectExceptionMessage('no valid transition');
        $tester->handle($this->movement->movePlayer(...), $this->move('0,1'));
    }

    #[Test]
    public function aMonsterBehindTheWallIsFoughtNormally(): void
    {
        $tester = $this->createTester(HeroType::WIZARD, items: ['0,1' => Item::createGiantRat()]);

        [, $messages] = $tester->handle($this->movement->movePlayer(...), $this->move('0,1'));

        $battle = $this->findMessage($messages, StartBattle::class);
        self::assertInstanceOf(StartBattle::class, $battle);
        self::assertSame('0,1', $battle->toPosition->toString());
        self::assertSame('0,0', $battle->fromPosition->toString());
        self::assertNull($this->findMessage($messages, PerformTurnAction::class), 'The fight, not the move, is recorded');
    }

    private function move(string $to): MovePlayer
    {
        return new MovePlayer(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            fromPosition: FieldPlace::fromString('0,0'),
            toPosition: FieldPlace::fromString($to),
        );
    }

    private function position(): string
    {
        return $this->movement->getPlayerPosition(new GetPlayerPosition($this->gameId, $this->playerId))->toString();
    }

    /**
     * @param array<string, Item> $items
     */
    private function createTester(?HeroType $hero, array $items = []): MessageBusTester
    {
        $field = new class ($items) {
            /** @param array<string, Item> $items */
            public function __construct(private readonly array $items) {}

            /** @return array<string, list<string>> */
            public function getDebugTransitions(): array
            {
                return ['0,0' => ['1,0'], '1,0' => ['0,0'], '0,1' => []];
            }

            /** @return array<string, Uuid> */
            public function getPlacedTiles(): array
            {
                return ['0,0' => Uuid::v7(), '1,0' => Uuid::v7(), '0,1' => Uuid::v7()];
            }

            /** @return array<string, Item> */
            public function getItems(): array
            {
                return $this->items;
            }

            /** @return array<string, list<FieldPlace>> */
            public function getTeleportationConnections(): array
            {
                return [];
            }

            public function getPendingFateweaver(): ?array
            {
                return null;
            }

            public function getPendingArena(): ?array
            {
                return null;
            }

            public function getDuelInfo(): ?array
            {
                return null;
            }
        };

        $player = new class ($hero) {
            public function __construct(private readonly ?HeroType $hero) {}

            public function getHP(): int
            {
                return 5;
            }

            public function getHero(): ?HeroType
            {
                return $this->hero;
            }

            public function getSkills(): ?\App\Game\Player\HeroSkills
            {
                $hero = $this->getHero();

                return $hero instanceof \App\Game\Player\HeroType ? \App\Game\Player\HeroSkills::of($hero) : null;
            }
        };

        $turn = GameTurn::start(new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: new \DateTimeImmutable(),
        ), startMessageContext());

        $movement = $this->movement;

        return MessageBusTester::create(
            static fn(GetField $_query): object => $field,
            static fn(GetPlayer $_query): object => $player,
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetTurn $_query): GameTurn => $turn,
            fn(GetGame $_query): Game => Game::create(new CreateGame($this->gameId), startMessageContext()),
            static fn(GetPlayerPosition $query): FieldPlace => $movement->getPlayerPosition($query),
        );
    }

    /**
     * @template T of object
     * @param list<object> $messages
     * @param class-string<T> $class
     * @return T|null
     */
    private function findMessage(array $messages, string $class): ?object
    {
        foreach ($messages as $message) {
            if ($message instanceof $class) {
                return $message;
            }
        }

        return null;
    }
}
