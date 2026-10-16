<?php

declare(strict_types=1);

namespace App\Tests\Game\GameLifecycle;

use App\Game\GameLifecycle\AddAiToTable;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateTable;
use App\Game\GameLifecycle\Error\CannotAddPlayerToAlreadyFullGame;
use App\Game\GameLifecycle\Error\TableRuleBroken;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GameStarted;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\LeaveTable;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\GameLifecycle\PlayerLeftTable;
use App\Game\GameLifecycle\StartTable;
use App\Game\GameLifecycle\TableClosed;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\MarkSeatAway;
use App\Game\Player\MarkSeatBack;
use App\Game\Player\PickHero;
use App\Game\Player\Player;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Online tables: the seat cap, one seat per account, host-only AI seats and start, the start rule,
 * leaving (the host leaving closes the table) and one hero per table.
 */
#[CoversClass(Game::class)]
final class TableTest extends TestCase
{
    private const string HOST = 'host-account';

    /**
     * @var array<string, Player>
     */
    private array $players = [];

    private Game $game;

    private MessageBusTester $bus;

    protected function setUp(): void
    {
        $this->players = [];
        $this->bus = MessageBusTester::create(
            fn(GetPlayer $query): Player => $this->players[$query->playerId->toString()],
            fn(GetGame $_query): Game => $this->game,
        );
    }

    #[Test]
    public function aTableHasTwoToFiveSeats(): void
    {
        foreach ([1, 6] as $seats) {
            try {
                $this->bus->handle(Game::createTable(...), new CreateTable(Uuid::v7(), self::HOST, $seats));
                self::fail("{$seats} seats is not a table");
            } catch (TableRuleBroken $e) {
                self::assertSame('table.seats', $e->reason);
            }
        }
        $this->openTable(2);
        self::assertTrue($this->game->isOnline());
        self::assertSame(2, $this->game->getSeats());
        self::assertSame(self::HOST, $this->game->getHostAccountId());
    }

    #[Test]
    public function theSeatsAreCappedAndAnAccountSitsOnce(): void
    {
        $this->openTable(2);
        $this->seat(self::HOST);

        try {
            $this->seat(self::HOST);
            self::fail('One seat per account');
        } catch (TableRuleBroken $e) {
            self::assertSame('table.already_seated', $e->reason);
        }

        $this->seat('guest');
        $this->expectException(CannotAddPlayerToAlreadyFullGame::class);
        $this->seat('third');
    }

    #[Test]
    public function onlyTheHostSeatsTheAiOneAtATime(): void
    {
        $this->openTable(3);
        $this->seat(self::HOST);

        try {
            $this->ai('guest');
            self::fail('A guest cannot seat the AI');
        } catch (TableRuleBroken $e) {
            self::assertSame('table.not_host', $e->reason);
        }

        $this->ai(self::HOST);
        $this->ai(self::HOST);
        self::assertCount(3, $this->game->getPlayers());
        $ai = $this->players[$this->game->getPlayers()[1]->toString()];
        self::assertTrue($ai->isAi());
        self::assertNotNull($ai->getChosenHero(), 'The AI is dealt a hero');

        $this->expectException(CannotAddPlayerToAlreadyFullGame::class);
        $this->ai(self::HOST);
    }

    #[Test]
    public function theHostStartsWithTwoSeatsAndEveryHeroChosen(): void
    {
        $this->openTable(4);
        $host = $this->seat(self::HOST);
        $this->pick($host, HeroType::WARRIOR);

        $this->assertStartRefused(self::HOST, 'table.too_few');
        $guest = $this->seat('guest');
        $this->assertStartRefused('guest', 'table.not_host');
        $this->assertStartRefused(self::HOST, 'table.hero_missing');

        $this->pick($guest, HeroType::THIEF);
        [, $messages] = $this->bus->handle($this->game->startTable(...), new StartTable($this->game->getGameId(), self::HOST));

        self::assertTrue($this->game->getStatus()->isInProgress());
        self::assertNotEmpty(array_filter($messages, static fn(object $m): bool => $m instanceof GameStarted));

        try {
            $this->seat('late');
            self::fail('A started table takes nobody');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(TableRuleBroken::class, $e);
        }
    }

    #[Test]
    public function randomHeroesAreDealtSoTheStartNeedsNoChoice(): void
    {
        $this->openTable(2, randomHeroes: true);
        $this->seat(self::HOST);
        $this->seat('guest');

        $this->bus->handle($this->game->startTable(...), new StartTable($this->game->getGameId(), self::HOST));

        self::assertTrue($this->game->getStatus()->isInProgress());
    }

    #[Test]
    public function aHeroStandsOncePerTable(): void
    {
        $this->openTable(2);
        $this->pick($this->seat(self::HOST), HeroType::ORACLE);
        $guest = $this->seat('guest');

        try {
            $this->pick($guest, HeroType::ORACLE);
            self::fail('The Oracle is taken');
        } catch (\DomainException $e) {
            self::assertStringContainsString('already taken', $e->getMessage());
        }
        $this->pick($guest, HeroType::WIZARD);
        self::assertSame(HeroType::WIZARD, $this->players[$guest->toString()]->getChosenHero());
    }

    #[Test]
    public function aGuestLeavingFreesTheSeatAndTheHostLeavingClosesTheTable(): void
    {
        $this->openTable(2);
        $this->seat(self::HOST);
        $guest = $this->seat('guest');
        $this->pick($guest, HeroType::THIEF);

        [, $messages] = $this->bus->handle($this->game->leaveTable(...), new LeaveTable($this->game->getGameId(), 'guest'));
        self::assertCount(1, $this->game->getPlayers());
        $left = array_values(array_filter($messages, static fn(object $m): bool => $m instanceof PlayerLeftTable));
        self::assertCount(1, $left);
        $this->players[$guest->toString()]->onLeftTable($left[0]);
        self::assertNull($this->players[$guest->toString()]->getExternalId());
        self::assertNull($this->players[$guest->toString()]->getChosenHero());

        $this->seat('another');
        self::assertCount(2, $this->game->getPlayers(), 'The freed seat is taken again');

        [, $messages] = $this->bus->handle($this->game->leaveTable(...), new LeaveTable($this->game->getGameId(), self::HOST));
        self::assertTrue($this->game->getStatus()->isFinished());
        self::assertSame('abandoned', $this->game->getEndReason());
        self::assertNotEmpty(array_filter($messages, static fn(object $m): bool => $m instanceof TableClosed));
    }

    #[Test]
    public function everyTurnOfATableWithATimeLimitHasADeadline(): void
    {
        $this->openTable(2, randomHeroes: true, turnSeconds: 60);
        $this->seat(self::HOST);
        $this->seat('guest');
        $at = new \DateTimeImmutable('2026-10-16 12:00:00');

        $this->bus->handle($this->game->startTable(...), new StartTable($this->game->getGameId(), self::HOST, $at));

        self::assertSame(60, $this->game->getTurnSeconds());
        self::assertEquals($at->modify('+60 seconds'), $this->game->getTurnDeadline());
    }

    #[Test]
    public function aTableWithoutALimitHasNoDeadline(): void
    {
        $this->openTable(2, randomHeroes: true, turnSeconds: null);
        $this->seat(self::HOST);
        $this->seat('guest');

        $this->bus->handle($this->game->startTable(...), new StartTable($this->game->getGameId(), self::HOST));

        self::assertNull($this->game->getTurnDeadline());
    }

    #[Test]
    public function aSeatIsAwayUntilItsPlayerIsBackAndTheAiNeverIs(): void
    {
        $this->openTable(3);
        $human = $this->seat(self::HOST);
        $this->ai(self::HOST);
        $player = $this->players[$human->toString()];
        $bot = $this->players[$this->game->getPlayers()[1]->toString()];
        $gameId = $this->game->getGameId();

        self::assertTrue($player->markAway(new MarkSeatAway($human, $gameId)));
        self::assertFalse($player->markAway(new MarkSeatAway($human, $gameId)), 'Already away');
        self::assertTrue($player->isAway());
        self::assertTrue($player->markBack(new MarkSeatBack($human, $gameId)));
        self::assertFalse($player->markBack(new MarkSeatBack($human, $gameId)), 'Already back');
        self::assertFalse($bot->markAway(new MarkSeatAway($bot->getPlayerId(), $gameId)), 'The AI is never away');
    }

    private function openTable(int $seats, bool $randomHeroes = false, ?int $turnSeconds = CreateTable::DEFAULT_TURN_SECONDS): void
    {
        [$this->game] = $this->bus->handle(Game::createTable(...), new CreateTable(Uuid::v7(), self::HOST, $seats, randomHeroes: $randomHeroes, turnSeconds: $turnSeconds));
    }

    private function seat(string $accountId): Uuid
    {
        $playerId = Uuid::v7();
        $this->join(fn() => $this->bus->handle($this->game->addPlayer(...), new AddPlayer(
            gameId: $this->game->getGameId(),
            playerId: $playerId,
            externalId: $accountId,
            username: $accountId,
        )));

        return $playerId;
    }

    private function ai(string $accountId): void
    {
        $this->join(fn() => $this->bus->handle($this->game->addAiToTable(...), new AddAiToTable($this->game->getGameId(), $accountId, Uuid::v7())));
    }

    /**
     * Runs a seating command and creates the players its PlayerAdded events announce.
     *
     * @param callable(): array{mixed, list<object>} $handle
     */
    private function join(callable $handle): void
    {
        [, $messages] = $handle();
        foreach ($messages as $message) {
            if ($message instanceof PlayerAdded) {
                $this->players[$message->playerId->toString()] = Player::onPlayerAddedToGame($message);
            }
        }
    }

    private function pick(Uuid $playerId, HeroType $hero): void
    {
        $player = $this->players[$playerId->toString()];
        $this->bus->handle($player->pickHero(...), new PickHero($this->game->getGameId(), $playerId, $hero));
    }

    private function assertStartRefused(string $accountId, string $reason): void
    {
        try {
            $this->bus->handle($this->game->startTable(...), new StartTable($this->game->getGameId(), $accountId));
            self::fail("The start should be refused: {$reason}");
        } catch (TableRuleBroken $e) {
            self::assertSame($reason, $e->reason);
        }
        self::assertTrue($this->game->getStatus()->isPreparing());
    }
}
