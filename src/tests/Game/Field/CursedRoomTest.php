<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Deck\DeckTile;
use App\Game\Field\CursedRoomEntered;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\Tile;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Player\GetPlayer;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnStarted;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Karak: Regent — every hero entering a Cursed Room rolls: 1–3 curses him, 4–6 lets him go, a cursed hero does not roll.
 */
#[CoversClass(Field::class)]
final class CursedRoomTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    private Field $field;

    private bool $cursed = false;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->cursed = false;
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());

        $tileId = Uuid::v7();
        (new \ReflectionProperty(Field::class, 'tiles'))->setValue($this->field, ['0,0' => Uuid::v7(), '1,0' => $tileId]);
        (new \ReflectionProperty(Field::class, 'tilesCache'))->setValue($this->field, [
            $tileId->toString() => Tile::fromDeckTile($tileId, DeckTile::createFromName('cursedRoom')),
        ]);
    }

    #[Test]
    public function aLowRollCursesTheHero(): void
    {
        $this->field->setTestDiceRolls([2]);
        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved());

        $entered = $this->findMessage($messages, CursedRoomEntered::class);
        self::assertInstanceOf(CursedRoomEntered::class, $entered);
        self::assertSame(2, $entered->roll);
        self::assertTrue($entered->cursed);
        self::assertTrue($entered->playerId->equals($this->playerId));
        self::assertSame('1,0', $entered->position->toString());

        self::assertSame(
            ['playerId' => $this->playerId->toString(), 'position' => '1,0', 'roll' => 2, 'cursed' => true, 'turnId' => $this->turnId->toString()],
            array_diff_key($this->field->getRoomEvent() ?? [], ['id' => true]),
        );
        self::assertSame([$this->field->getRoomEvent()], $this->field->getRoomEvents());

        // a second room in the same turn (a Sprint): both rolls are kept, in order
        $this->field->setTestDiceRolls([5]);
        $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved());
        self::assertCount(2, $this->field->getRoomEvents());
        self::assertSame([2, 5], array_column($this->field->getRoomEvents(), 'roll'));

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::CURSED_ROOM, $action->action);
        self::assertSame(2, $action->additionalData['roll'] ?? null);
        self::assertTrue($action->additionalData['cursed'] ?? false);
    }

    #[Test]
    public function aHighRollIsAvoided(): void
    {
        $this->field->setTestDiceRolls([5]);
        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved());

        $entered = $this->findMessage($messages, CursedRoomEntered::class);
        self::assertInstanceOf(CursedRoomEntered::class, $entered);
        self::assertSame(5, $entered->roll);
        self::assertFalse($entered->cursed);
    }

    #[Test]
    public function aCursedHeroDoesNotRoll(): void
    {
        $this->cursed = true;
        $this->field->setTestDiceRolls([2, 4]);
        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved());

        self::assertSame([], $messages);
        self::assertSame(2, $this->field->getNextDiceRoll(), 'The die was not spent');
        // the entry is told all the same: the browser says the room does nothing to a cursed hero
        $event = $this->field->getRoomEvent();
        self::assertNotNull($event);
        self::assertTrue($event['alreadyCursed'] ?? false);
        self::assertNull($event['roll']);
    }

    #[Test]
    public function beingPushedBackOntoTheRoomRollsAgain(): void
    {
        $this->field->setTestDiceRolls([3]);
        [, $messages] = $this->createTester()->handle($this->field->onPlayerMoved(...), $this->moved(isBattleReturn: true));

        $entered = $this->findMessage($messages, CursedRoomEntered::class);
        self::assertInstanceOf(CursedRoomEntered::class, $entered);
        self::assertTrue($entered->cursed);
    }

    #[Test]
    public function theRollOutlivesTheTurnSoARollOnTheLastStepIsSeen(): void
    {
        $this->field->setTestDiceRolls([5]);
        $tester = $this->createTester();
        $tester->handle($this->field->onPlayerMoved(...), $this->moved());
        $roll = $this->field->getRoomEvent();
        self::assertNotNull($roll);

        // the step was the turn's last: the next turn starts before any browser reads the state
        $tester->handle($this->field->turnStarted(...), new TurnStarted(Uuid::v7(), $this->gameId, $this->playerId, 2, new \DateTimeImmutable()));
        self::assertSame($roll, $this->field->getRoomEvent());
    }

    private function moved(bool $isBattleReturn = false): PlayerMoved
    {
        return new PlayerMoved(
            gameId: $this->gameId,
            playerId: $this->playerId,
            fromPosition: FieldPlace::fromString('0,0'),
            toPosition: FieldPlace::fromString('1,0'),
            movedAt: new \DateTimeImmutable(),
            isBattleReturn: $isBattleReturn,
        );
    }

    private function createTester(): MessageBusTester
    {
        $cursed = &$this->cursed;

        return MessageBusTester::create(
            static fn(GetPlayer $_query): object => new class ($cursed) {
                public function __construct(private readonly bool $cursed) {}

                public function isCursed(): bool
                {
                    return $this->cursed;
                }

                public function isDefeated(): bool
                {
                    return false;
                }
            },
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
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
