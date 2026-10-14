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
 * Thief "Stealth": entering a monster tile without fighting is allowed only for the Thief.
 */
#[CoversClass(Movement::class)]
final class ThiefStealthTest extends TestCase
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
        $gameId = $this->gameId;
        $playerId = $this->playerId;
        MessageBusTester::create(
            static fn(GetGame $_query): object => new class($playerId) {
                public function __construct(private readonly Uuid $playerId) {}

                /** @return list<Uuid> */
                public function getPlayers(): array
                {
                    return [$this->playerId];
                }
            },
        )->handle($this->movement->initializeStartingPositions(...), new GameStarted($gameId, new \DateTimeImmutable(), [$playerId]));
    }

    #[Test]
    public function thiefSneaksPastAMonsterWithoutABattle(): void
    {
        $tester = $this->createTester(HeroType::THIEF);

        [, $messages] = $tester->handle($this->movement->movePlayer(...), $this->move(ignoreMonster: true));

        self::assertNull($this->findMessage($messages, StartBattle::class), 'No battle when sneaking');
        $moved = $this->findMessage($messages, PlayerMoved::class);
        self::assertInstanceOf(PlayerMoved::class, $moved);
        self::assertSame('1,0', $moved->toPosition->toString());

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::MOVE, $action->action);
        self::assertTrue($action->additionalData['sneakedPastMonster'] ?? false);
        self::assertSame('stealth', $action->additionalData['heroAbility'] ?? null);
        self::assertSame('giant_rat', $action->additionalData['monster']['name'] ?? null);
    }

    #[Test]
    public function thiefCanStillChooseToFight(): void
    {
        $tester = $this->createTester(HeroType::THIEF);

        [, $messages] = $tester->handle($this->movement->movePlayer(...), $this->move(ignoreMonster: false));

        self::assertInstanceOf(StartBattle::class, $this->findMessage($messages, StartBattle::class));
        self::assertNull($this->findMessage($messages, PerformTurnAction::class), 'The fight is recorded by the battle, not as a move');
    }

    #[Test]
    public function otherHeroesCannotSneakPastAMonster(): void
    {
        $tester = $this->createTester(HeroType::WARRIOR);

        $this->expectException(InvalidMovementException::class);
        $this->expectExceptionMessage('Stealth');
        $tester->handle($this->movement->movePlayer(...), $this->move(ignoreMonster: true));
    }

    #[Test]
    public function ignoreMonsterIsHarmlessWhenNoMonsterIsThere(): void
    {
        $tester = $this->createTester(null, monsterAtDestination: false);

        [, $messages] = $tester->handle($this->movement->movePlayer(...), $this->move(ignoreMonster: true));

        self::assertInstanceOf(PlayerMoved::class, $this->findMessage($messages, PlayerMoved::class));
        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertArrayNotHasKey('sneakedPastMonster', $action->additionalData ?? []);
    }

    private function move(bool $ignoreMonster): MovePlayer
    {
        return new MovePlayer(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            fromPosition: new FieldPlace(0, 0),
            toPosition: new FieldPlace(1, 0),
            ignoreMonster: $ignoreMonster,
        );
    }

    private function createTester(?HeroType $hero, bool $monsterAtDestination = true): MessageBusTester
    {
        $field = new class($monsterAtDestination) {
            /** @var array<string, Item> */
            private array $items = [];

            public function __construct(bool $monsterAtDestination)
            {
                if ($monsterAtDestination) {
                    $this->items['1,0'] = Item::createGiantRat();
                }
            }

            /** @return array<string, list<string>> */
            public function getDebugTransitions(): array
            {
                return ['0,0' => ['1,0'], '1,0' => ['0,0']];
            }

            /** @return array<string, Uuid> */
            public function getPlacedTiles(): array
            {
                return ['0,0' => Uuid::v7(), '1,0' => Uuid::v7()];
            }

            /** @return array<string, Item> */
            public function getItems(): array
            {
                return $this->items;
            }

            public function getTeleportationConnections(): array
            {
                return [];
            }
        };

        $player = new class($hero) {
            public function __construct(private readonly ?HeroType $hero) {}

            public function getHP(): int
            {
                return 5;
            }

            public function getHero(): ?HeroType
            {
                return $this->hero;
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
     * @param list<object> $messages
     * @param class-string $class
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
