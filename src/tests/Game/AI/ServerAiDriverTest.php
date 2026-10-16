<?php

declare(strict_types=1);

namespace App\Tests\Game\AI;

use App\Game\AI\AiTurnTaker;
use App\Game\AI\ServerAiDriver;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GetGame;
use App\Game\Player\GetPlayer;
use App\Game\Player\MarkSeatAway;
use App\Infrastructure\Push\Publisher;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The server plays the AI seats of an online game: only online games in progress, only while an AI seat is due,
 * at most five turns in a row, never re-entered, and every turn is pushed as `ai.turn`.
 */
#[CoversClass(ServerAiDriver::class)]
final class ServerAiDriverTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $human;

    private Uuid $bot;

    /** @var array{online: bool, inProgress: bool, current: Uuid, turn: int, deadline: ?\DateTimeImmutable} */
    private array $state;

    /** @var array<string, bool> seats marked away */
    private array $away = [];

    /** @var list<array{string, array<string, mixed>}> */
    private array $published = [];

    /** @var list<Uuid> */
    private array $seats = [];

    /** Karak: Regent — a duel in the current turn waits for a human */
    private bool $duelAwaitsHuman = false;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->human = Uuid::v7();
        $this->bot = Uuid::v7();
        $this->published = [];
        $this->away = [];
        $this->duelAwaitsHuman = false;
        $this->state = ['online' => true, 'inProgress' => true, 'current' => $this->bot, 'turn' => 3, 'deadline' => null];
    }

    #[Test]
    public function itPlaysTheDueAiTurnAndPushesIt(): void
    {
        $played = $this->driver(fn() => $this->pass($this->human))->drive($this->gameId);

        self::assertSame(1, $played);
        self::assertCount(1, $this->published);
        [$topic, $data] = $this->published[0];
        self::assertSame('karak/game/' . $this->gameId->toString(), $topic);
        self::assertSame('ai.turn', $data['type']);
        self::assertSame($this->bot->toString(), $data['playerId']);
        self::assertSame(3, $data['turn']);
        self::assertFalse($data['standIn']);
        self::assertSame([['type' => 'end_turn']], $data['actions']);
    }

    #[Test]
    public function anAiTurnWaitingForAHumanInADuelIsNotPlayedUntilHeAnswers(): void
    {
        $this->duelAwaitsHuman = true;
        $played = $this->driver(fn() => $this->pass($this->human))->drive($this->gameId);

        self::assertSame(0, $played, 'The human rolls first; his request drives the turn on');
        self::assertSame([], $this->published);

        $this->duelAwaitsHuman = false;
        self::assertSame(1, $this->driver(fn() => $this->pass($this->human))->drive($this->gameId));
    }

    #[Test]
    public function itKeepsPlayingWhileTheNextSeatIsAnAiButFiveTurnsAtMost(): void
    {
        $played = $this->driver(fn() => $this->pass($this->bot))->drive($this->gameId);

        self::assertSame(ServerAiDriver::MAX_TURNS, $played);
        self::assertCount(ServerAiDriver::MAX_TURNS, $this->published);
    }

    #[Test]
    public function itLeavesHumansOfflineGamesAndGamesNotInProgressAlone(): void
    {
        foreach ([['current' => $this->human], ['online' => false], ['inProgress' => false]] as $change) {
            $this->setUp();
            $this->state = array_merge($this->state, $change);
            self::assertSame(0, $this->driver(static fn() => self::fail('No AI turn is due'))->drive($this->gameId));
            self::assertSame([], $this->published);
        }
    }

    #[Test]
    public function aTurnThatDoesNotMoveOnStopsTheDriver(): void
    {
        $played = $this->driver(static fn(): array => [])->drive($this->gameId);

        self::assertSame(1, $played);
    }

    #[Test]
    public function itIsNotReenteredFromInsideAnAiTurn(): void
    {
        $driver = null;
        $inner = null;
        $driver = $this->driver(function () use (&$driver, &$inner): array {
            $inner = $driver->drive($this->gameId);

            return $this->pass($this->human);
        });

        self::assertSame(1, $driver->drive($this->gameId));
        self::assertSame(0, $inner);
    }

    #[Test]
    public function anAwaySeatIsPlayedAtOnceWhileSomeoneIsAtTheTable(): void
    {
        $other = Uuid::v7();
        $this->state['current'] = $this->human;
        $this->away[$this->human->toString()] = true;
        $this->seats = [$this->human, $other];

        $played = $this->driver(fn() => $this->pass($other))->drive($this->gameId);

        self::assertSame(1, $played);
        self::assertTrue($this->published[0][1]['standIn']);
    }

    #[Test]
    public function withEveryHumanAwayOnlyTheClockMovesTheGame(): void
    {
        $this->state['current'] = $this->human;
        $this->away[$this->human->toString()] = true;
        $this->seats = [$this->human];

        self::assertSame(0, $this->driver(static fn() => self::fail('Nobody is at the table'))->drive($this->gameId));
    }

    #[Test]
    public function anOverdueTurnIsPlayedForItsSeatWhichIsAwayFromThen(): void
    {
        $this->state['current'] = $this->human;
        $this->state['deadline'] = new \DateTimeImmutable('-1 second');
        $this->seats = [$this->human, $this->bot];

        $standIn = $this->driver(fn() => $this->pass($this->human))->standIn($this->gameId);

        self::assertTrue($standIn);
        self::assertTrue($this->away[$this->human->toString()] ?? false, 'The seat is away');
        self::assertTrue($this->published[0][1]['standIn']);
        self::assertSame($this->human->toString(), $this->published[0][1]['playerId']);
    }

    #[Test]
    public function aTurnWithTimeLeftIsNotTakenOver(): void
    {
        $this->state['current'] = $this->human;
        $this->state['deadline'] = new \DateTimeImmutable('+30 seconds');

        self::assertFalse($this->driver(static fn() => self::fail('Time is left'))->standIn($this->gameId));
        self::assertSame([], $this->away);
    }

    /**
     * Ends the current turn and hands it to the given seat.
     *
     * @return list<array<string, mixed>>
     */
    private function pass(Uuid $next): array
    {
        $this->state['current'] = $next;
        ++$this->state['turn'];

        return [['type' => 'end_turn']];
    }

    /**
     * @param callable(): list<array<string, mixed>> $turn
     */
    private function driver(callable $turn): ServerAiDriver
    {
        $test = $this;
        $bus = MessageBusTester::create(
            fn(GetGame $_query): object => new class ($test->state, $test->seats) {
                /**
                 * @param array{online: bool, inProgress: bool, current: Uuid, turn: int, deadline: ?\DateTimeImmutable} $state
                 * @param list<Uuid> $seats
                 */
                public function __construct(private readonly array $state, private readonly array $seats) {}

                public function getPlayers(): array
                {
                    return $this->seats;
                }

                public function getTurnDeadline(): ?\DateTimeImmutable
                {
                    return $this->state['deadline'];
                }

                public function getCurrentTurnId(): Uuid
                {
                    return Uuid::v7();
                }

                public function isOnline(): bool
                {
                    return $this->state['online'];
                }

                public function isInProgress(): bool
                {
                    return $this->state['inProgress'];
                }

                public function getCurrentPlayerId(): Uuid
                {
                    return $this->state['current'];
                }

                public function getCurrentTurnNumber(): int
                {
                    return $this->state['turn'];
                }
            },
            fn(GetPlayer $query): object => new class ($query->playerId->equals($test->bot), $test->away[$query->playerId->toString()] ?? false) {
                public function __construct(private readonly bool $ai, private readonly bool $away) {}

                public function isAi(): bool
                {
                    return $this->ai;
                }

                public function isAway(): bool
                {
                    return $this->away;
                }
            },
            fn(GetField $_query): object => new class ($test->duelAwaitsHuman) {
                public function __construct(private readonly bool $awaits) {}

                public function duelAwaitsHuman(): bool
                {
                    return $this->awaits;
                }
            },
            static function (MarkSeatAway $command) use ($test): bool {
                $test->away[$command->playerId->toString()] = true;

                return true;
            },
        )->messageBus();

        $ai = new class ($turn) implements AiTurnTaker {
            /** @var \Closure(): list<array<string, mixed>> */
            private \Closure $turn;

            public function __construct(callable $turn)
            {
                $this->turn = $turn(...);
            }

            public function executeTurn(Uuid $gameId, Uuid $playerId, string $strategyType = 'balanced'): array
            {
                return ($this->turn)();
            }
        };
        $publisher = new class ($this->published) implements Publisher {
            /** @param list<array{string, array<string, mixed>}> $published */
            public function __construct(private array &$published) {}

            public function publish(string $topic, array $data): void
            {
                $this->published[] = [$topic, $data];
            }
        };

        return new ServerAiDriver($bus, $ai, $publisher, new NullLogger());
    }
}
