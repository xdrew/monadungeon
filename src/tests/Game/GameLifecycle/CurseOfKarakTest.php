<?php

declare(strict_types=1);

namespace App\Tests\Game\GameLifecycle;

use App\Game\Battle\MummyDefeated;
use App\Game\Field\FieldPlace;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\CursePlayer;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\NextTurn;
use App\Game\GameLifecycle\StartGame;
use App\Game\Player\CurseLifted;
use App\Game\Player\GetPlayer;
use App\Game\Player\PlayerCursed;
use App\Game\Player\PlayerHealedAtFountain;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnEnded;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Curse of Karak: the Game owns the single curse token and the slayer's pending choice.
 */
#[CoversClass(Game::class)]
final class CurseOfKarakTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $p1;

    private Uuid $p2;

    private Uuid $p3;

    /** @var array<string, bool> playerId => isAi */
    private array $aiPlayers = [];

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->p1 = Uuid::v7();
        $this->p2 = Uuid::v7();
        $this->p3 = Uuid::v7();
        $this->aiPlayers = [];
    }

    #[Test]
    public function theSlayerChoosesTheVictim(): void
    {
        $game = $this->startedGame([$this->p1, $this->p2, $this->p3]);
        $tester = $this->createTester();

        [, $messages] = $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p1));
        self::assertTrue($game->getCurseChoicePendingBy()?->equals($this->p1));
        self::assertNull($game->getCursedPlayerId());
        self::assertSame([], $messages, 'A human slayer gets the choice, nothing happens yet');

        [, $messages] = $tester->handle($game->cursePlayer(...), $this->curse($game, $this->p1, $this->p2));

        self::assertTrue($game->getCursedPlayerId()?->equals($this->p2));
        self::assertNull($game->getCurseChoicePendingBy());
        $cursed = $this->findMessage($messages, PlayerCursed::class);
        self::assertInstanceOf(PlayerCursed::class, $cursed);
        self::assertTrue($cursed->playerId->equals($this->p2));
        self::assertTrue($cursed->byPlayerId->equals($this->p1));
        self::assertNull($this->findMessage($messages, CurseLifted::class), 'Nobody held the token before');
        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::CURSE, $action->action);
        self::assertSame($this->p2->toString(), $action->additionalData['target'] ?? null);
        self::assertFalse($action->additionalData['kept'] ?? true);
        self::assertArrayHasKey('previous', $action->additionalData ?? []);
        self::assertNull($action->additionalData['previous']);
    }

    #[Test]
    public function theTokenMovesOnAndCanBeKept(): void
    {
        $game = $this->startedGame([$this->p1, $this->p2, $this->p3]);
        $tester = $this->createTester();
        $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p1));
        $tester->handle($game->cursePlayer(...), $this->curse($game, $this->p1, $this->p2));

        // The next mummy, beaten by P3 in another turn: the token moves from P2 to P1
        $this->nextTurn($game, $tester);
        $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p3));
        [, $messages] = $tester->handle($game->cursePlayer(...), $this->curse($game, $this->p3, $this->p1));

        $lifted = $this->findMessage($messages, CurseLifted::class);
        self::assertInstanceOf(CurseLifted::class, $lifted);
        self::assertTrue($lifted->playerId->equals($this->p2));
        $cursed = $this->findMessage($messages, PlayerCursed::class);
        self::assertInstanceOf(PlayerCursed::class, $cursed);
        self::assertTrue($cursed->playerId->equals($this->p1));
        self::assertTrue($game->getCursedPlayerId()?->equals($this->p1));
        self::assertSame($this->p2->toString(), $this->findMessage($messages, PerformTurnAction::class)?->additionalData['previous'] ?? null);

        // A third mummy, beaten by P2: the token stays on P1
        $this->nextTurn($game, $tester);
        $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p2));
        [, $messages] = $tester->handle($game->cursePlayer(...), $this->curse($game, $this->p2, null));

        self::assertNull($this->findMessage($messages, CurseLifted::class));
        self::assertNull($this->findMessage($messages, PlayerCursed::class));
        self::assertTrue($game->getCursedPlayerId()?->equals($this->p1));
        self::assertNull($game->getCurseChoicePendingBy());
        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertTrue($action->additionalData['kept'] ?? false);
        self::assertSame($this->p1->toString(), $action->additionalData['target'] ?? null);
    }

    #[Test]
    public function invalidChoicesAreRejected(): void
    {
        $game = $this->startedGame([$this->p1, $this->p2]);
        $tester = $this->createTester();

        $this->assertDomainError($tester, $game, $this->curse($game, $this->p1, $this->p2), 'No curse to place');

        $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p1));
        $this->assertDomainError($tester, $game, $this->curse($game, $this->p1, $this->p1), 'cannot curse yourself');
        $this->assertDomainError($tester, $game, $this->curse($game, $this->p1, Uuid::v7()), 'Unknown player');
        $this->assertDomainError($tester, $game, $this->curse($game, $this->p1, null), 'not in play yet');
        $this->assertDomainError($tester, $game, $this->curse($game, $this->p2, $this->p1), 'No curse to place');
        $this->assertDomainError($tester, $game, new CursePlayer($this->gameId, $this->p1, Uuid::v7(), $this->p2), 'Invalid turn ID');

        self::assertTrue($game->getCurseChoicePendingBy()?->equals($this->p1), 'The choice is still pending');
        self::assertNull($game->getCursedPlayerId());
    }

    #[Test]
    public function aLonelyPlayerCannotCurseAnyone(): void
    {
        $game = $this->startedGame([$this->p1]);
        $tester = $this->createTester();

        [, $messages] = $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p1));

        self::assertNull($game->getCurseChoicePendingBy());
        self::assertSame([], $messages);
    }

    #[Test]
    public function theChoiceIsOfferedOncePerTurn(): void
    {
        $game = $this->startedGame([$this->p1, $this->p2]);
        $tester = $this->createTester();
        $event = $this->mummyDefeated($game, $this->p1);

        $tester->handle($game->onMummyDefeated(...), $event);
        $tester->handle($game->onMummyDefeated(...), $event);
        self::assertTrue($game->getCurseChoicePendingBy()?->equals($this->p1));

        $tester->handle($game->cursePlayer(...), $this->curse($game, $this->p1, $this->p2));
        // The finalized win re-processes the result in the same turn: no second choice
        $tester->handle($game->onMummyDefeated(...), $event);
        self::assertNull($game->getCurseChoicePendingBy());
        self::assertTrue($game->getCursedPlayerId()?->equals($this->p2));
    }

    #[Test]
    public function aSecondMummyInTheSameTurnOffersTheChoiceAgain(): void
    {
        $game = $this->startedGame([$this->p1, $this->p2]);
        $tester = $this->createTester();
        $turnId = $game->getCurrentTurnId();
        self::assertNotNull($turnId);
        $firstFight = new MummyDefeated($this->gameId, $this->p1, $turnId, Uuid::v7());

        $tester->handle($game->onMummyDefeated(...), $firstFight);
        $tester->handle($game->cursePlayer(...), $this->curse($game, $this->p1, $this->p2));
        // The same fight finalized: no second choice
        $tester->handle($game->onMummyDefeated(...), $firstFight);
        self::assertNull($game->getCurseChoicePendingBy());

        // Unstoppable: another mummy beaten in the same turn is a new fight and the slayer chooses again
        $tester->handle($game->onMummyDefeated(...), new MummyDefeated($this->gameId, $this->p1, $turnId, Uuid::v7()));
        self::assertTrue($game->getCurseChoicePendingBy()?->equals($this->p1));
    }

    #[Test]
    public function aVirtualSlayerCursesTheFirstHuman(): void
    {
        $game = $this->startedGame([$this->p1, $this->p2, $this->p3]);
        $this->aiPlayers[$this->p1->toString()] = true;
        $this->aiPlayers[$this->p2->toString()] = true;
        $tester = $this->createTester();

        [, $messages] = $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p1));

        self::assertNull($game->getCurseChoicePendingBy(), 'A virtual slayer never waits');
        self::assertTrue($game->getCursedPlayerId()?->equals($this->p3), 'The first human, not the other AI');
        $cursed = $this->findMessage($messages, PlayerCursed::class);
        self::assertInstanceOf(PlayerCursed::class, $cursed);
        self::assertTrue($cursed->playerId->equals($this->p3));
        self::assertNull($this->findMessage($messages, PerformTurnAction::class), 'No turn action for the automatic choice');
    }

    #[Test]
    public function anUnmadeChoiceIsSettledWhenTheTurnEnds(): void
    {
        $game = $this->startedGame([$this->p1, $this->p2, $this->p3]);
        $tester = $this->createTester();

        // No token in play: the first other player gets it
        $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p2));
        [, $messages] = $tester->handle($game->turnEnded(...), new TurnEnded($game->getCurrentTurnId(), $this->gameId, $this->p2, new \DateTimeImmutable()));

        self::assertNull($game->getCurseChoicePendingBy());
        self::assertTrue($game->getCursedPlayerId()?->equals($this->p1));
        self::assertInstanceOf(PlayerCursed::class, $this->findMessage($messages, PlayerCursed::class));
        self::assertInstanceOf(NextTurn::class, $this->findMessage($messages, NextTurn::class));

        // Token in play: it simply stays
        $this->nextTurn($game, $tester);
        $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p3));
        [, $messages] = $tester->handle($game->turnEnded(...), new TurnEnded($game->getCurrentTurnId(), $this->gameId, $this->p3, new \DateTimeImmutable()));

        self::assertNull($game->getCurseChoicePendingBy());
        self::assertTrue($game->getCursedPlayerId()?->equals($this->p1));
        self::assertNull($this->findMessage($messages, PlayerCursed::class));
        self::assertNull($this->findMessage($messages, CurseLifted::class));
        self::assertInstanceOf(NextTurn::class, $this->findMessage($messages, NextTurn::class));
    }

    #[Test]
    public function healingAtAFountainLiftsTheCurse(): void
    {
        $game = $this->startedGame([$this->p1, $this->p2]);
        $tester = $this->createTester();
        $tester->handle($game->onMummyDefeated(...), $this->mummyDefeated($game, $this->p1));
        $tester->handle($game->cursePlayer(...), $this->curse($game, $this->p1, $this->p2));

        [, $messages] = $tester->handle($game->onPlayerHealedAtFountain(...), new PlayerHealedAtFountain($this->p1, $this->gameId, FieldPlace::fromString('0,0')));
        self::assertSame([], $messages, 'The slayer is not cursed');
        self::assertTrue($game->getCursedPlayerId()?->equals($this->p2));

        [, $messages] = $tester->handle($game->onPlayerHealedAtFountain(...), new PlayerHealedAtFountain($this->p2, $this->gameId, FieldPlace::fromString('0,0')));
        $lifted = $this->findMessage($messages, CurseLifted::class);
        self::assertInstanceOf(CurseLifted::class, $lifted);
        self::assertTrue($lifted->playerId->equals($this->p2));
        self::assertNull($game->getCursedPlayerId());
    }

    /**
     * @param list<Uuid> $players
     */
    private function startedGame(array $players): Game
    {
        [$game] = handle(Game::create(...), new CreateGame(gameId: $this->gameId, at: new \DateTimeImmutable(), deckSize: 88));
        $tester = MessageBusTester::create();
        foreach ($players as $playerId) {
            $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $playerId));
        }
        $tester->handle($game->start(...), new StartGame($this->gameId, new \DateTimeImmutable()));

        return $game;
    }

    private function createTester(): MessageBusTester
    {
        $aiPlayers = &$this->aiPlayers;

        return MessageBusTester::create(
            static fn(GetPlayer $query): object => new class ($aiPlayers[$query->playerId->toString()] ?? false) {
                public function __construct(private readonly bool $isAi) {}

                public function isAi(): bool
                {
                    return $this->isAi;
                }

                public function isDefeated(): bool
                {
                    return false;
                }
            },
        );
    }

    private function nextTurn(Game $game, MessageBusTester $tester): void
    {
        $tester->handle($game->nextTurn(...), new NextTurn($this->gameId));
    }

    private function mummyDefeated(Game $game, Uuid $slayer): MummyDefeated
    {
        $turnId = $game->getCurrentTurnId();
        self::assertNotNull($turnId);

        return new MummyDefeated($this->gameId, $slayer, $turnId);
    }

    private function curse(Game $game, Uuid $by, ?Uuid $target): CursePlayer
    {
        $turnId = $game->getCurrentTurnId();
        self::assertNotNull($turnId);

        return new CursePlayer($this->gameId, $by, $turnId, $target);
    }

    private function assertDomainError(MessageBusTester $tester, Game $game, CursePlayer $command, string $message): void
    {
        try {
            $tester->handle($game->cursePlayer(...), $command);
            self::fail("Expected a DomainException containing '{$message}'");
        } catch (\DomainException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
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
