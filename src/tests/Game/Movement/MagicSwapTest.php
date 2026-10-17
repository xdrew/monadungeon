<?php

declare(strict_types=1);

namespace App\Tests\Game\Movement;

use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GameStarted;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Movement\Commands\MagicSwap;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\Events\PlayersSwapped;
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
 * Warlock "Magic Swap": trade places with another hero as the first action of the turn.
 */
#[CoversClass(Movement::class)]
final class MagicSwapTest extends TestCase
{
    private Uuid $gameId;
    private Uuid $playerId;
    private Uuid $targetId;
    private Uuid $turnId;
    private Movement $movement;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->targetId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->movement = Movement::create(new GameCreated($this->gameId, new \DateTimeImmutable()));

        $players = [$this->playerId, $this->targetId];
        MessageBusTester::create(
            static fn(GetGame $_query): object => new class($players) {
                /** @param list<Uuid> $players */
                public function __construct(private readonly array $players) {}

                /** @return list<Uuid> */
                public function getPlayers(): array
                {
                    return $this->players;
                }
            },
        )->handle($this->movement->initializeStartingPositions(...), new GameStarted($this->gameId, new \DateTimeImmutable(), $players));

        // The other hero already explored the dungeon
        $this->movement->resetPlayerPosition(new ResetPlayerPosition($this->gameId, $this->targetId, FieldPlace::fromString('1,0')));
    }

    #[Test]
    public function warlockTradesPlacesWithAnotherHero(): void
    {
        $tester = $this->createTester(HeroType::WARLOCK);

        [, $messages] = $tester->handle($this->movement->magicSwap(...), $this->swap());

        self::assertSame('1,0', $this->position($this->playerId));
        self::assertSame('0,0', $this->position($this->targetId));

        $swapped = $this->findMessage($messages, PlayersSwapped::class);
        self::assertInstanceOf(PlayersSwapped::class, $swapped);
        self::assertTrue($swapped->targetPlayerId->equals($this->targetId));
        self::assertSame('0,0', $swapped->playerFrom->toString());
        self::assertSame('1,0', $swapped->playerTo->toString());
        self::assertNull($this->findMessage($messages, PlayerMoved::class), 'A swap is not a move: no battle or fountain side effects');

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::MAGIC_SWAP, $action->action);
        self::assertFalse($action->deferTurnEnd, 'Nothing to pick up: the turn ends right away');
        self::assertSame('magic_swap', $action->additionalData['heroAbility'] ?? null);
        self::assertSame($this->targetId->toString(), $action->additionalData['targetPlayerId'] ?? null);
        self::assertSame('0,0', $action->additionalData['from'] ?? null);
        self::assertSame('1,0', $action->additionalData['to'] ?? null);
    }

    #[Test]
    public function aRewardOnTheNewTileKeepsTheTurnOpenForPickup(): void
    {
        // A defeated monster: its reward is still lying on the tile
        $tester = $this->createTester(HeroType::WARLOCK, items: ['1,0' => Item::createGiantRat()->defeatMonster()]);

        [, $messages] = $tester->handle($this->movement->magicSwap(...), $this->swap());

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertTrue($action->deferTurnEnd, 'The Warlock may still unlock the chest / pick the item where he arrived');
    }

    #[Test]
    public function noSwapWithAHeroStandingOnAMonster(): void
    {
        // a Thief sneaked onto a monster's tile: the Warlock would land on it without a fight
        $tester = $this->createTester(HeroType::WARLOCK, items: ['1,0' => Item::createGiantRat()]);

        $this->expectException(InvalidMovementException::class);
        $this->expectExceptionMessage('That hero stands on a monster');
        $tester->handle($this->movement->magicSwap(...), $this->swap());
    }

    #[Test]
    public function swapIsOnlyPossibleAtTheBeginningOfTheTurn(): void
    {
        $tester = $this->createTester(HeroType::WARLOCK, actionsBefore: [TurnAction::MOVE]);

        try {
            $tester->handle($this->movement->magicSwap(...), $this->swap());
            self::fail('Expected InvalidMovementException');
        } catch (InvalidMovementException $exception) {
            self::assertStringContainsString('beginning of the turn', $exception->getMessage());
        }

        self::assertSame('0,0', $this->position($this->playerId), 'Positions stay untouched');
        self::assertSame('1,0', $this->position($this->targetId));
    }

    #[Test]
    public function onlyTheWarlockCanSwapPlaces(): void
    {
        $tester = $this->createTester(HeroType::THIEF);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only the Warlock');
        $tester->handle($this->movement->magicSwap(...), $this->swap());
    }

    #[Test]
    public function warlockCannotSwapWithHimself(): void
    {
        $tester = $this->createTester(HeroType::WARLOCK);

        $this->expectException(InvalidMovementException::class);
        $tester->handle($this->movement->magicSwap(...), new MagicSwap(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            targetPlayerId: $this->playerId,
        ));
    }

    private function swap(): MagicSwap
    {
        return new MagicSwap(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            targetPlayerId: $this->targetId,
        );
    }

    private function position(Uuid $playerId): string
    {
        return $this->movement->getPlayerPosition(new GetPlayerPosition($this->gameId, $playerId))->toString();
    }

    /**
     * @param array<string, Item> $items
     * @param list<TurnAction> $actionsBefore
     */
    private function createTester(?HeroType $hero, array $items = [], array $actionsBefore = []): MessageBusTester
    {
        $field = new class($items) {
            /** @param array<string, Item> $items */
            public function __construct(private readonly array $items) {}

            /** @return array<string, Item> */
            public function getItems(): array
            {
                return $this->items;
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

            public function getSkills(): ?\App\Game\Player\HeroSkills
            {
                $hero = $this->getHero();

                return $hero instanceof \App\Game\Player\HeroType ? \App\Game\Player\HeroSkills::of($hero) : null;
            }
        };

        $turn = GameTurn::start(new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: new \DateTimeImmutable(),
        ), startMessageContext());
        foreach ($actionsBefore as $action) {
            MessageBusTester::create()->handle($turn->performAction(...), new PerformTurnAction(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                action: $action,
                at: new \DateTimeImmutable(),
            ));
        }

        return MessageBusTester::create(
            static fn(GetField $_query): object => $field,
            static fn(GetPlayer $_query): object => $player,
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetTurn $_query): GameTurn => $turn,
            fn(GetGame $_query): Game => Game::create(new CreateGame($this->gameId), startMessageContext()),
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
