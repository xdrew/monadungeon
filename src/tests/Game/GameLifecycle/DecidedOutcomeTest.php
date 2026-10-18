<?php

declare(strict_types=1);

namespace App\Tests\Game\GameLifecycle;

use App\Game\Field\GetField;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\EndGame;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\NextTurn;
use App\Game\GameLifecycle\StartGame;
use App\Game\Item\Item;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Player\GetPlayer;
use App\Game\Turn\TurnEnded;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Once nothing new can come into play and nobody reaches the leader's score with every treasure left on the field,
 * the game ends at the end of the turn ('decided') instead of being played out.
 */
#[CoversClass(Game::class)]
#[CoversClass(\App\Game\Field\Field::class)]
final class DecidedOutcomeTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $leader;

    private Uuid $behind;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->leader = Uuid::v7();
        $this->behind = Uuid::v7();
    }

    #[Test]
    public function aLeadNobodyCanCatchEndsTheGame(): void
    {
        $game = $this->startedGame();

        // 16 against 8 with only the ruby (3) left
        $messages = $this->endTurn($game, chests: [$this->leader->toString() => 8, $this->behind->toString() => 4], pointsLeft: 3);

        $end = $this->find($messages, EndGame::class);
        self::assertInstanceOf(EndGame::class, $end);
        self::assertSame('decided', $end->reason);
        self::assertNull($this->find($messages, NextTurn::class));
    }

    #[Test]
    public function theRubyThatStillTiesTheScoreKeepsTheGameGoing(): void
    {
        $game = $this->startedGame();

        // 10 against 8: the ruby makes it 10 against 11
        $messages = $this->endTurn($game, chests: [$this->leader->toString() => 5, $this->behind->toString() => 4], pointsLeft: 3);

        self::assertNull($this->find($messages, EndGame::class));
        self::assertInstanceOf(NextTurn::class, $this->find($messages, NextTurn::class));
    }

    #[Test]
    public function aFieldThatCanStillChangeKeepsTheGameGoing(): void
    {
        $game = $this->startedGame();

        // a tile in the deck or an arena still to be activated
        $messages = $this->endTurn($game, chests: [$this->leader->toString() => 8, $this->behind->toString() => 0], pointsLeft: null);

        self::assertNull($this->find($messages, EndGame::class));
        self::assertInstanceOf(NextTurn::class, $this->find($messages, NextTurn::class));
    }

    #[Test]
    public function theEndReasonIsKept(): void
    {
        $game = $this->startedGame();
        $tester = $this->tester([$this->leader->toString() => 8, $this->behind->toString() => 4], 3);

        $tester->handle($game->endGame(...), new EndGame($this->gameId, reason: 'decided'));

        self::assertSame('decided', $game->getEndReason());
        self::assertTrue($game->getWinnerIds()[0]->equals($this->leader));
    }

    #[Test]
    public function theFieldCountsItsTreasuresOnceTheDeckIsEmpty(): void
    {
        $field = \App\Game\Field\Field::create(new \App\Game\GameLifecycle\GameCreated($this->gameId, new \DateTimeImmutable(), 10), \App\Tests\Infrastructure\MessageBus\startMessageContext());
        (new \ReflectionProperty(\App\Game\Field\Field::class, 'items'))->setValue($field, [
            '3,6' => new Item(ItemName::DRAGON, ItemType::RUBY_CHEST),
            '1,0' => new Item(ItemName::GIANT_RAT, ItemType::DAGGER, 5, 0, true),
        ]);
        $deck = static fn(bool $empty): \Closure => static fn(\App\Game\Deck\GetDeck $_query): object => new class ($empty) {
            public function __construct(private readonly bool $empty) {}

            public function isEmpty(): bool
            {
                return $this->empty;
            }
        };

        self::assertSame(3, $field->pointsLeftInPlay(MessageBusTester::create($deck(true))->createMessageContext()));
        self::assertNull($field->pointsLeftInPlay(MessageBusTester::create($deck(false))->createMessageContext()), 'Tiles still in the deck');
    }

    /**
     * @param array<string, int> $chests player id => regular chests (2 points each)
     * @return list<object>
     */
    private function endTurn(Game $game, array $chests, ?int $pointsLeft): array
    {
        $playerId = $game->getCurrentPlayerId();
        self::assertNotNull($playerId);
        [, $messages] = $this->tester($chests, $pointsLeft)->handle(
            $game->turnEnded(...),
            new TurnEnded($game->getCurrentTurnId(), $this->gameId, $playerId, new \DateTimeImmutable()),
        );

        return $messages;
    }

    /**
     * @param array<string, int> $chests
     */
    private function tester(array $chests, ?int $pointsLeft): MessageBusTester
    {
        return MessageBusTester::create(
            static fn(GetPlayer $query): object => new class ($chests[$query->playerId->toString()] ?? 0) {
                public function __construct(private readonly int $chests) {}

                /**
                 * @return array<string, list<Item>>
                 */
                public function getInventory(): array
                {
                    return ['treasure' => array_map(
                        static fn(): Item => new Item(ItemName::TREASURE_CHEST, ItemType::CHEST),
                        $this->chests > 0 ? range(1, $this->chests) : [],
                    )];
                }
            },
            static fn(GetField $_query): object => new class ($pointsLeft) {
                public function __construct(private readonly ?int $pointsLeft) {}

                public function pointsLeftInPlay(): ?int
                {
                    return $this->pointsLeft;
                }
            },
        );
    }

    private function startedGame(): Game
    {
        [$game] = handle(Game::create(...), new CreateGame(gameId: $this->gameId, at: new \DateTimeImmutable(), deckSize: 88));
        $tester = MessageBusTester::create();
        foreach ([$this->leader, $this->behind] as $playerId) {
            $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $playerId));
        }
        $tester->handle($game->start(...), new StartGame($this->gameId, new \DateTimeImmutable()));

        return $game;
    }

    /**
     * @template T of object
     * @param list<object> $messages
     * @param class-string<T> $class
     * @return T|null
     */
    private function find(array $messages, string $class): ?object
    {
        foreach ($messages as $message) {
            if ($message instanceof $class) {
                return $message;
            }
        }

        return null;
    }
}
