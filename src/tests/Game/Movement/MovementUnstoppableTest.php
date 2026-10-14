<?php

declare(strict_types=1);

namespace App\Tests\Game\Movement;

use App\Game\Battle\BattleCompleted;
use App\Game\Battle\BattleResult;
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
use App\Game\Movement\Exception\CannotMoveAfterBattleException;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Movement\Movement;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Turn\GameTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\StartTurn;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Swordsman "Unstoppable": a battle with a 6 on the dice does not lock the rest of the turn,
 * so the hero may walk onto the monster tile again and fight once more.
 */
#[CoversClass(Movement::class)]
final class MovementUnstoppableTest extends TestCase
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
    public function anUnstoppableBattleDoesNotLockMovementSoTheMonsterCanBeFoughtAgain(): void
    {
        $this->movement->onBattleCompleted($this->battleCompleted(unstoppable: true));

        $tester = $this->createTester(HeroType::SWORDSMAN);
        [, $messages] = $tester->handle($this->movement->movePlayer(...), $this->move());

        $battle = $this->findMessage($messages, StartBattle::class);
        self::assertInstanceOf(StartBattle::class, $battle);
        self::assertSame('1,0', $battle->toPosition->toString());
        self::assertSame('giant_rat', $battle->monster->name->value);
    }

    #[Test]
    public function aRegularBattleStillLocksMovementForTheRestOfTheTurn(): void
    {
        $this->movement->onBattleCompleted($this->battleCompleted(unstoppable: false));

        $tester = $this->createTester(HeroType::SWORDSMAN);

        $this->expectException(CannotMoveAfterBattleException::class);
        $tester->handle($this->movement->movePlayer(...), $this->move());
    }

    private function battleCompleted(bool $unstoppable): BattleCompleted
    {
        return new BattleCompleted(
            battleId: Uuid::v7(),
            gameId: $this->gameId,
            playerId: $this->playerId,
            result: BattleResult::LOSE,
            diceResults: [6, 2],
            diceRollDamage: 8,
            itemDamage: 0,
            totalDamage: 8,
            monsterHP: 5,
            unstoppable: $unstoppable,
        );
    }

    private function move(): MovePlayer
    {
        return new MovePlayer(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            fromPosition: new FieldPlace(0, 0),
            toPosition: new FieldPlace(1, 0),
            ignoreMonster: false,
        );
    }

    private function createTester(?HeroType $hero): MessageBusTester
    {
        $field = new class {
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
                return ['1,0' => Item::createGiantRat()];
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
                return 2;
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
