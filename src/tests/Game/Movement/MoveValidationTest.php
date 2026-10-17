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
use App\Game\Movement\GetPlayerPosition;
use App\Game\Movement\Movement;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroSkills;
use App\Game\Turn\Error\InvalidTurnActionException;
use App\Game\Turn\Error\TurnAlreadyEndedException;
use App\Game\Turn\GameTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnActionPerformed;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * A step the turn refuses leaves the hero where he stands: the turn is asked before the position changes
 * (an ended turn, the moves spent, a reward waiting after the 4th move, a tile picked but not placed).
 */
#[CoversClass(Movement::class)]
#[CoversClass(GameTurn::class)]
final class MoveValidationTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    private Movement $movement;

    private GameTurn $turn;

    /** @var list<object> what the refused step must not have dispatched */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();

        $this->movement = Movement::create(new GameCreated($this->gameId, new \DateTimeImmutable()));
        $playerId = $this->playerId;
        MessageBusTester::create(
            static fn(GetGame $_query): object => new class ($playerId) {
                public function __construct(private readonly Uuid $playerId) {}

                /** @return list<Uuid> */
                public function getPlayers(): array
                {
                    return [$this->playerId];
                }
            },
        )->handle($this->movement->initializeStartingPositions(...), new GameStarted($this->gameId, new \DateTimeImmutable(), [$playerId]));

        $this->turn = GameTurn::start(new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: new \DateTimeImmutable(),
        ), startMessageContext());
    }

    #[Test]
    public function aStepWhileATileIsPickedIsRefusedAndTheHeroStays(): void
    {
        $this->perform(TurnAction::PICK_TILE);

        $this->assertRefused(InvalidTurnActionException::class, monsterAhead: false);
    }

    #[Test]
    public function aFifthStepIsRefusedOnceTheTurnHasEnded(): void
    {
        for ($step = 0; $step < 4; ++$step) {
            $this->perform(TurnAction::MOVE);
        }
        self::assertTrue($this->turn->isEnded());

        $this->assertRefused(TurnAlreadyEndedException::class, monsterAhead: false);
    }

    #[Test]
    public function aFifthStepIsRefusedWhileTheRewardOfTheFourthWaits(): void
    {
        for ($step = 0; $step < 3; ++$step) {
            $this->perform(TurnAction::MOVE);
        }
        $this->perform(TurnAction::MOVE, deferTurnEnd: true);
        self::assertFalse($this->turn->isEnded(), 'The reward of the 4th step may still be picked up');

        $this->assertRefused(InvalidTurnActionException::class, monsterAhead: false);
    }

    #[Test]
    public function noFightStartsWhenTheMovesAreSpent(): void
    {
        for ($step = 0; $step < 4; ++$step) {
            $this->perform(TurnAction::MOVE);
        }

        $this->assertRefused(TurnAlreadyEndedException::class, monsterAhead: true);
    }

    #[Test]
    public function afterPlacingATileTheStepGoesOntoItOnly(): void
    {
        $this->perform(TurnAction::PICK_TILE);
        $this->perform(TurnAction::PLACE_TILE, additionalData: ['fieldPlace' => '0,1']);

        $this->assertRefused(\App\Game\Movement\Exception\InvalidMovementException::class, monsterAhead: false);
    }

    #[Test]
    public function anAllowedStepStillMoves(): void
    {
        $this->tester(monsterAhead: false)->handle($this->movement->movePlayer(...), $this->move());

        self::assertSame('1,0', $this->position());
        self::assertNotEmpty(array_filter($this->seen, static fn(object $m): bool => $m instanceof PlayerMoved));
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    private function assertRefused(string $exception, bool $monsterAhead): void
    {
        $tester = $this->tester($monsterAhead);

        try {
            $tester->handle($this->movement->movePlayer(...), $this->move());
            self::fail('The step should have been refused');
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
        }

        self::assertSame('0,0', $this->position(), 'A refused step leaves the hero where he stands');
        self::assertSame([], $this->seen, 'Nothing moved and no fight started');
    }

    private function perform(TurnAction $action, bool $deferTurnEnd = false, ?array $additionalData = null): void
    {
        // like on the real bus, the counter moves while the action is performed (the 4th move ends the turn)
        $turn = $this->turn;
        MessageBusTester::create(static fn(TurnActionPerformed $event) => $turn->updateActionCounter($event))->handle($this->turn->performAction(...), new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: $action,
            at: new \DateTimeImmutable(),
            deferTurnEnd: $deferTurnEnd,
            additionalData: $additionalData,
        ));
    }

    private function position(): string
    {
        return $this->movement->getPlayerPosition(new GetPlayerPosition($this->gameId, $this->playerId))->toString();
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

    private function tester(bool $monsterAhead): MessageBusTester
    {
        $field = new class ($monsterAhead) {
            public function __construct(private readonly bool $monsterAhead) {}

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
                return $this->monsterAhead ? ['1,0' => Item::createGiantRat()] : [];
            }

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
        };

        $player = new class () {
            public function getHP(): int
            {
                return 5;
            }

            public function getSkills(): ?HeroSkills
            {
                return null;
            }
        };

        $turn = $this->turn;
        $movement = $this->movement;

        return MessageBusTester::create(
            static fn(GetField $_query): object => $field,
            static fn(GetPlayer $_query): object => $player,
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetTurn $_query): GameTurn => $turn,
            fn(GetGame $_query): Game => Game::create(new CreateGame($this->gameId), startMessageContext()),
            static fn(GetPlayerPosition $query): FieldPlace => $movement->getPlayerPosition($query),
            static fn(PerformTurnAction $command) => null,
            function (PlayerMoved $event): void { $this->seen[] = $event; },
            function (StartBattle $command): void { $this->seen[] = $command; },
        );
    }
}
