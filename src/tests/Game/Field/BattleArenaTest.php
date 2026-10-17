<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Deck\DeckTile;
use App\Game\Duel\BeginDuel;
use App\Game\Duel\SettleDuel;
use App\Game\Duel\StartDuel;
use App\Game\Field\ArenaEntered;
use App\Game\Field\ChooseDuelOpponent;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\Tile;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\Events\PlayersSwapped;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetActivePlayers;
use App\Game\Player\GetPlayer;
use App\Game\Player\Player;
use App\Game\Turn\Error\NotYourTurnException;
use App\Game\Turn\GameTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\GetTurn;
use App\Game\Item\Item;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnActionPerformed;
use App\Game\Turn\TurnEnded;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Karak: Regent — the first entry of a Battle Arena by the active hero summons a rival to a duel; it activates once.
 */
#[CoversClass(Field::class)]
final class BattleArenaTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $p1;

    private Uuid $p2;

    private Uuid $p3;

    private Uuid $turnId;

    private Field $field;

    /** @var array<string, Player> */
    private array $players = [];

    /** @var list<Uuid> */
    private array $activePlayers = [];

    private int $movesSpent = 0;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->p1 = Uuid::v7();
        $this->p2 = Uuid::v7();
        $this->p3 = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->movesSpent = 0;
        $this->players = [];
        foreach ([$this->p1, $this->p2, $this->p3] as $playerId) {
            $this->players[$playerId->toString()] = Player::onPlayerAddedToGame(new PlayerAdded($this->gameId, $playerId));
        }
        $this->activePlayers = [$this->p1, $this->p2, $this->p3];
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());

        $tileId = Uuid::v7();
        (new \ReflectionProperty(Field::class, 'tiles'))->setValue($this->field, ['0,0' => Uuid::v7(), '1,0' => $tileId]);
        (new \ReflectionProperty(Field::class, 'tilesCache'))->setValue($this->field, [
            $tileId->toString() => Tile::fromDeckTile($tileId, DeckTile::createFromName('battleArena')),
        ]);
    }

    #[Test]
    public function aHumanChoosesARival(): void
    {
        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));

        $pending = $this->field->getPendingArena();
        self::assertSame(
            ['position' => '1,0', 'playerId' => $this->p1->toString(), 'turnId' => $this->turnId->toString(), 'firstStep' => true],
            array_diff_key($pending ?? [], ['candidates' => true]),
        );
        self::assertNotContains($this->p1->toString(), $pending['candidates'] ?? [], 'The rivals are the others');
        self::assertInstanceOf(ArenaEntered::class, $this->findMessage($messages, ArenaEntered::class));
        self::assertNull($this->findMessage($messages, StartDuel::class));
        self::assertSame(['1,0'], array_map(static fn(FieldPlace $p): string => $p->toString(), $this->field->getActivatedArenas()));
        self::assertSame(
            ['position' => '1,0', 'playerId' => $this->p1->toString(), 'candidates' => [$this->p2->toString(), $this->p3->toString()]],
            $this->field->getArenaChoice($this->activePlayers),
        );
    }

    #[Test]
    public function anUnconsciousHeroCannotBeSummoned(): void
    {
        (new \ReflectionProperty(Player::class, 'hp'))->setValue($this->players[$this->p2->toString()], 0);

        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));

        // the only fit rival is summoned at once
        $start = $this->findMessage($messages, StartDuel::class);
        self::assertInstanceOf(StartDuel::class, $start);
        self::assertTrue($start->opponentId->equals($this->p3));
        self::assertNull($this->field->getPendingArena());
    }

    #[Test]
    public function aRivalNeverChosenIsPickedWhenTheTurnEnds(): void
    {
        $tester = $this->createTester();
        $tester->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));
        (new \ReflectionProperty(Player::class, 'inventory'))->setValue($this->players[$this->p3->toString()], [
            'key' => [], 'weapon' => [], 'spell' => [], 'treasure' => [Item::createTreasureChest()],
        ]);

        [, $messages] = $tester->handle($this->field->onTurnEnded(...), new TurnEnded($this->turnId, $this->gameId, $this->p1, new \DateTimeImmutable()));

        $start = $this->findMessage($messages, StartDuel::class);
        self::assertInstanceOf(StartDuel::class, $start, 'The duel is not optional');
        self::assertTrue($start->opponentId->equals($this->p3), 'The rival with the most treasures');
        self::assertNull($this->field->getPendingArena());
    }

    #[Test]
    public function aRetreatOntoAnArenaDoesNotOpenIt(): void
    {
        $retreat = new PlayerMoved(
            gameId: $this->gameId,
            playerId: $this->p1,
            fromPosition: FieldPlace::fromString('0,0'),
            toPosition: FieldPlace::fromString('1,0'),
            movedAt: new \DateTimeImmutable(),
            isBattleReturn: true,
        );

        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $retreat);

        self::assertSame([], $this->field->getActivatedArenas(), 'Only a hero who walks in opens the arena');
        self::assertNull($this->findMessage($messages, StartDuel::class));
        self::assertNull($this->field->getPendingArena());
    }

    #[Test]
    public function aMoveSpentBeforeTheArenaIsNotTheFirstStep(): void
    {
        $this->movesSpent = 2;
        $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));

        self::assertFalse($this->field->getPendingArena()['firstStep'] ?? true);
    }

    #[Test]
    public function theChosenRivalIsMovedOntoTheArenaAndTheDuelBegins(): void
    {
        $tester = $this->createTester();
        $tester->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));

        [, $messages] = $tester->handle($this->field->chooseDuelOpponent(...), $this->choose($this->p1, $this->p2));

        $reset = $this->findMessage($messages, ResetPlayerPosition::class);
        self::assertInstanceOf(ResetPlayerPosition::class, $reset);
        self::assertTrue($reset->playerId->equals($this->p2));
        self::assertSame('1,0', $reset->position->toString());

        $start = $this->findMessage($messages, StartDuel::class);
        self::assertInstanceOf(StartDuel::class, $start);
        self::assertTrue($start->challengerId->equals($this->p1));
        self::assertTrue($start->opponentId->equals($this->p2));
        self::assertTrue($start->firstStep);
        self::assertSame('1,0', $start->arenaPosition->toString());

        $begin = $this->findMessage($messages, BeginDuel::class);
        self::assertInstanceOf(BeginDuel::class, $begin);
        self::assertTrue($begin->duelId->equals($start->duelId), 'The duel is begun after it was created');
        self::assertNull($this->field->getPendingArena());
    }

    #[Test]
    public function invalidChoicesAreRejected(): void
    {
        $tester = $this->createTester();
        $this->assertChoiceFails($tester, $this->choose($this->p1, $this->p2), \DomainException::class, 'No arena choice pending');

        $tester->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));
        $this->assertChoiceFails($tester, $this->choose($this->p1, $this->p1), \DomainException::class, 'cannot duel yourself');
        $this->assertChoiceFails($tester, $this->choose($this->p1, Uuid::v7()), \DomainException::class, 'Unknown player');
        $this->assertChoiceFails($tester, $this->choose($this->p2, $this->p3), NotYourTurnException::class, '');

        self::assertNotNull($this->field->getPendingArena(), 'The choice is still open');
    }

    #[Test]
    public function theArenaActivatesOnlyOnce(): void
    {
        $tester = $this->createTester();
        $tester->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));
        (new \ReflectionProperty(Field::class, 'pendingArena'))->setValue($this->field, null);

        [, $messages] = $tester->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));

        self::assertSame([], $messages, 'Later entries are a normal corridor');
        self::assertNull($this->field->getPendingArena());
        self::assertCount(1, $this->field->getActivatedArenas());
    }

    #[Test]
    public function aVirtualChallengerSummonsTheRichestRival(): void
    {
        $this->players[$this->p1->toString()] = Player::onPlayerAddedToGame(new PlayerAdded($this->gameId, $this->p1, isAi: true));
        $this->players[$this->p3->toString()]->setTestInventory(['treasure_chest', 'treasure_chest']);

        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));

        $start = $this->findMessage($messages, StartDuel::class);
        self::assertInstanceOf(StartDuel::class, $start);
        self::assertTrue($start->opponentId->equals($this->p3));
        self::assertInstanceOf(BeginDuel::class, $this->findMessage($messages, BeginDuel::class));
        self::assertNull($this->field->getPendingArena());
        self::assertNull($this->findMessage($messages, ArenaEntered::class));
    }

    #[Test]
    public function withOneRivalTheDuelBeginsAtOnce(): void
    {
        $this->activePlayers = [$this->p1, $this->p2];
        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));

        self::assertNull($this->field->getPendingArena(), 'Nobody to choose from: no choice is asked');
        self::assertNull($this->findMessage($messages, ArenaEntered::class));
        $start = $this->findMessage($messages, StartDuel::class);
        self::assertInstanceOf(StartDuel::class, $start);
        self::assertTrue($start->challengerId->equals($this->p1));
        self::assertTrue($start->opponentId->equals($this->p2));
        self::assertTrue($start->firstStep);
        self::assertInstanceOf(BeginDuel::class, $this->findMessage($messages, BeginDuel::class));
        $reset = $this->findMessage($messages, ResetPlayerPosition::class);
        self::assertInstanceOf(ResetPlayerPosition::class, $reset);
        self::assertTrue($reset->playerId->equals($this->p2));
    }

    #[Test]
    public function aLonelyHeroActivatesTheArenaWithoutADuel(): void
    {
        $this->activePlayers = [$this->p1];
        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));

        self::assertSame([], $messages);
        self::assertCount(1, $this->field->getActivatedArenas());
        self::assertNull($this->field->getPendingArena());
    }

    #[Test]
    public function aHeroSwappedOntoTheArenaOutOfTurnDoesNotActivateIt(): void
    {
        [, $messages] = $this->createTester()->handle($this->field->onPlayersSwapped(...), new PlayersSwapped(
            gameId: $this->gameId,
            playerId: $this->p1,
            targetPlayerId: $this->p2,
            playerFrom: FieldPlace::fromString('1,0'),
            playerTo: FieldPlace::fromString('5,5'),
        ));

        self::assertSame([], $messages);
        self::assertSame([], $this->field->getActivatedArenas());
    }

    #[Test]
    public function theTurnEndDropsTheChoiceAndSettlesAnOpenDuel(): void
    {
        $tester = $this->createTester();
        $tester->handle($this->field->onPlayerMoved(...), $this->moved($this->p1));
        $duelId = Uuid::v7();
        (new \ReflectionProperty(Field::class, 'duelInfo'))->setValue($this->field, ['duelId' => $duelId->toString(), 'phase' => 'opponent_roll']);

        [, $messages] = $tester->handle($this->field->onTurnEnded(...), new TurnEnded($this->turnId, $this->gameId, $this->p1, new \DateTimeImmutable()));

        self::assertNull($this->field->getPendingArena());
        $settle = $this->findMessage($messages, SettleDuel::class);
        self::assertInstanceOf(SettleDuel::class, $settle);
        self::assertTrue($settle->duelId->equals($duelId));

        (new \ReflectionProperty(Field::class, 'duelInfo'))->setValue($this->field, ['duelId' => $duelId->toString(), 'phase' => 'done']);
        [, $messages] = $tester->handle($this->field->onTurnEnded(...), new TurnEnded($this->turnId, $this->gameId, $this->p1, new \DateTimeImmutable()));
        self::assertNull($this->findMessage($messages, SettleDuel::class));
    }

    private function moved(Uuid $playerId): PlayerMoved
    {
        return new PlayerMoved(
            gameId: $this->gameId,
            playerId: $playerId,
            fromPosition: FieldPlace::fromString('0,0'),
            toPosition: FieldPlace::fromString('1,0'),
            movedAt: new \DateTimeImmutable(),
        );
    }

    private function choose(Uuid $playerId, Uuid $opponentId): ChooseDuelOpponent
    {
        return new ChooseDuelOpponent($this->gameId, $playerId, $this->turnId, $opponentId);
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    private function assertChoiceFails(MessageBusTester $tester, ChooseDuelOpponent $command, string $exception, string $message): void
    {
        try {
            $tester->handle($this->field->chooseDuelOpponent(...), $command);
            self::fail('Expected ' . $exception);
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function createTester(): MessageBusTester
    {
        $players = &$this->players;
        $activePlayers = &$this->activePlayers;
        $turn = $this->createTurn($this->movesSpent);

        return MessageBusTester::create(
            static fn(GetPlayer $query): Player => $players[$query->playerId->toString()],
            static function (GetActivePlayers $_query) use (&$activePlayers): array {
                return $activePlayers;
            },
            fn(GetCurrentPlayer $_query): Uuid => $this->p1,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetTurn $_query): GameTurn => $turn,
            static fn(GetPlayerPosition $_query): FieldPlace => FieldPlace::fromString('1,0'),
        );
    }

    private function createTurn(int $movesSpent): GameTurn
    {
        $at = new \DateTimeImmutable();
        [$turn] = handle(GameTurn::start(...), new StartTurn($this->gameId, $this->p1, 1, $this->turnId, $at));
        for ($i = 0; $i < $movesSpent; ++$i) {
            $turn->updateActionCounter(new TurnActionPerformed($this->turnId, $this->gameId, $this->p1, TurnAction::MOVE, $at));
        }

        return $turn;
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
