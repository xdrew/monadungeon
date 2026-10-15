<?php

declare(strict_types=1);

namespace App\Tests\Game\GameLifecycle;

use App\Game\Battle\MummyDefeated;
use App\Game\Field\CursedRoomEntered;
use App\Game\Field\FieldPlace;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\CursePlayer;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\StartGame;
use App\Game\Player\CurseLifted;
use App\Game\Player\GetPlayer;
use App\Game\Player\PlayerCursed;
use App\Game\Player\PlayerHealedAtFountain;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Karak: Regent — the expansion flag on the Game and the Cursed Room curses next to the single mummy token.
 */
#[CoversClass(Game::class)]
final class RegentGameTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $p1;

    private Uuid $p2;

    private Uuid $p3;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->p1 = Uuid::v7();
        $this->p2 = Uuid::v7();
        $this->p3 = Uuid::v7();
    }

    #[Test]
    public function theExpansionFlagIsStoredAndAnnounced(): void
    {
        [$game, $messages] = handle(Game::create(...), new CreateGame(gameId: $this->gameId, regent: true));

        self::assertTrue($game->isRegent());
        $created = $messages[0] ?? null;
        self::assertInstanceOf(GameCreated::class, $created);
        self::assertTrue($created->regent);

        [$base] = handle(Game::create(...), new CreateGame(gameId: Uuid::v7()));
        self::assertFalse($base->isRegent());
    }

    #[Test]
    public function aCursedRoomRollOfOneToThreeCursesTheHero(): void
    {
        $game = $this->startedGame();
        $tester = MessageBusTester::create();

        [, $messages] = $tester->handle($game->onCursedRoomEntered(...), $this->roomEntered($this->p2, cursed: false));
        self::assertSame([], $messages);
        self::assertFalse($game->isPlayerCursed($this->p2));

        [, $messages] = $tester->handle($game->onCursedRoomEntered(...), $this->roomEntered($this->p2, cursed: true));
        $cursed = $this->findMessage($messages, PlayerCursed::class);
        self::assertInstanceOf(PlayerCursed::class, $cursed);
        self::assertTrue($cursed->playerId->equals($this->p2));
        self::assertTrue($game->isPlayerCursed($this->p2));
        self::assertNull($game->getCursedPlayerId(), 'The mummy token is not involved');

        $tester->handle($game->onCursedRoomEntered(...), $this->roomEntered($this->p2, cursed: true));
        $tester->handle($game->onCursedRoomEntered(...), $this->roomEntered($this->p3, cursed: true));
        self::assertCount(2, $game->getRoomCursedPlayerIds(), 'Several heroes may be cursed at once, each one once');
    }

    #[Test]
    public function aFountainLiftsBothCurseSourcesWithOneEvent(): void
    {
        $game = $this->startedGame();
        $tester = MessageBusTester::create();
        $tester->handle($game->onCursedRoomEntered(...), $this->roomEntered($this->p2, cursed: true));

        [, $messages] = $tester->handle($game->onPlayerHealedAtFountain(...), $this->healed($this->p2));
        self::assertCount(1, array_filter($messages, static fn(object $m): bool => $m instanceof CurseLifted));
        self::assertSame([], $game->getRoomCursedPlayerIds());
        self::assertFalse($game->isPlayerCursed($this->p2));

        // The token holder is room-cursed too
        $tester->handle($game->onCursedRoomEntered(...), $this->roomEntered($this->p3, cursed: true));
        (new \ReflectionProperty(Game::class, 'cursedPlayerId'))->setValue($game, $this->p3);
        [, $messages] = $tester->handle($game->onPlayerHealedAtFountain(...), $this->healed($this->p3));
        self::assertCount(1, array_filter($messages, static fn(object $m): bool => $m instanceof CurseLifted));
        self::assertNull($game->getCursedPlayerId());
        self::assertSame([], $game->getRoomCursedPlayerIds());

        [, $messages] = $tester->handle($game->onPlayerHealedAtFountain(...), $this->healed($this->p1));
        self::assertSame([], $messages, 'An uncursed hero healing lifts nothing');
    }

    #[Test]
    public function theMummyTokenRespectsARoomCurse(): void
    {
        $game = $this->startedGame();
        $tester = $this->humanTester();
        $tester->handle($game->onCursedRoomEntered(...), $this->roomEntered($this->p2, cursed: true));
        (new \ReflectionProperty(Game::class, 'cursedPlayerId'))->setValue($game, $this->p2);

        // P1 beats a mummy and moves the token from P2 to P3: P2 stays cursed by the room
        $tester->handle($game->onMummyDefeated(...), new MummyDefeated($this->gameId, $this->p1, $this->turnId($game)));
        [, $messages] = $tester->handle($game->cursePlayer(...), new CursePlayer($this->gameId, $this->p1, $this->turnId($game), $this->p3));

        self::assertNull($this->findMessage($messages, CurseLifted::class));
        self::assertTrue($game->isPlayerCursed($this->p2));
        self::assertTrue($game->isPlayerCursed($this->p3));

        // The token lands on a room-cursed hero: still announced (the Player flag is idempotent)
        (new \ReflectionProperty(Game::class, 'curseChoiceTurnId'))->setValue($game, null);
        $tester->handle($game->onMummyDefeated(...), new MummyDefeated($this->gameId, $this->p1, $this->turnId($game)));
        [, $messages] = $tester->handle($game->cursePlayer(...), new CursePlayer($this->gameId, $this->p1, $this->turnId($game), $this->p2));
        $cursed = $this->findMessage($messages, PlayerCursed::class);
        self::assertInstanceOf(PlayerCursed::class, $cursed);
        self::assertTrue($cursed->playerId->equals($this->p2));
        $lifted = $this->findMessage($messages, CurseLifted::class);
        self::assertInstanceOf(CurseLifted::class, $lifted, 'P3 held only the token');
        self::assertTrue($lifted->playerId->equals($this->p3));
    }

    private function startedGame(): Game
    {
        [$game] = handle(Game::create(...), new CreateGame(gameId: $this->gameId, regent: true));
        $tester = MessageBusTester::create();
        foreach ([$this->p1, $this->p2, $this->p3] as $playerId) {
            $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $playerId));
        }
        $tester->handle($game->start(...), new StartGame($this->gameId, new \DateTimeImmutable()));

        return $game;
    }

    private function humanTester(): MessageBusTester
    {
        return MessageBusTester::create(
            static fn(GetPlayer $_query): object => new class () {
                public function isAi(): bool
                {
                    return false;
                }
            },
        );
    }

    private function turnId(Game $game): Uuid
    {
        $turnId = $game->getCurrentTurnId();
        self::assertNotNull($turnId);

        return $turnId;
    }

    private function roomEntered(Uuid $playerId, bool $cursed): CursedRoomEntered
    {
        return new CursedRoomEntered($this->gameId, $playerId, FieldPlace::fromString('1,0'), $cursed ? 2 : 5, $cursed);
    }

    private function healed(Uuid $playerId): PlayerHealedAtFountain
    {
        return new PlayerHealedAtFountain(
            gameId: $this->gameId,
            playerId: $playerId,
            position: FieldPlace::fromString('0,0'),
            healedAt: new \DateTimeImmutable(),
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
