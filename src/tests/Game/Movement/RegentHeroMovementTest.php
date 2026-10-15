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
use App\Game\Movement\Commands\DeclareSprint;
use App\Game\Movement\Commands\LightningAttack;
use App\Game\Movement\Events\PlayerMoved;
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
use App\Game\Turn\TurnActionPerformed;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Karak: Regent heroes moving: Baron Markul "Lightning Attack", Hanna "Sprint", and Valduk "Tireless"
 * unlocking the movement after a fight.
 */
#[CoversClass(Movement::class)]
final class RegentHeroMovementTest extends TestCase
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
        $players = [$this->playerId];
        MessageBusTester::create(
            static fn(GetGame $_query): object => new class ($players) {
                /** @param list<Uuid> $players */
                public function __construct(private readonly array $players) {}

                /** @return list<Uuid> */
                public function getPlayers(): array
                {
                    return $this->players;
                }
            },
        )->handle($this->movement->initializeStartingPositions(...), new GameStarted($this->gameId, new \DateTimeImmutable(), $players));
    }

    #[Test]
    public function markulTeleportsOntoAMonsterAndFightsIt(): void
    {
        $rat = Item::createGiantRat();
        $tester = $this->createTester(HeroType::BATTLE_MAGE, ['3,2' => $rat]);

        [, $messages] = $tester->handle(
            $this->movement->lightningAttack(...),
            new LightningAttack($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString('3,2')),
        );

        self::assertSame('3,2', $this->movement->getPlayerPosition(new GetPlayerPosition($this->gameId, $this->playerId))->toString());
        $moved = $this->findMessage($messages, PlayerMoved::class);
        self::assertInstanceOf(PlayerMoved::class, $moved);
        self::assertSame('0,0', $moved->fromPosition->toString());

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::LIGHTNING_ATTACK, $action->action);
        self::assertSame('lightning_attack', $action->additionalData['heroAbility'] ?? null);

        $battle = $this->findMessage($messages, StartBattle::class);
        self::assertInstanceOf(StartBattle::class, $battle);
        self::assertTrue($battle->lightning);
        self::assertSame('0,0', $battle->fromPosition->toString());
        self::assertSame('3,2', $battle->toPosition->toString());
        self::assertTrue($battle->monster->itemId->equals($rat->itemId));
    }

    #[Test]
    public function lightningAttackOnlyOpensTheTurn(): void
    {
        $tester = $this->createTester(HeroType::BATTLE_MAGE, ['3,2' => Item::createGiantRat()], [TurnAction::PICK_TILE]);

        $this->expectException(InvalidMovementException::class);
        $tester->handle($this->movement->lightningAttack(...), new LightningAttack($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString('3,2')));
    }

    #[Test]
    public function lightningAttackNeedsAnUndefeatedMonster(): void
    {
        $tester = $this->createTester(HeroType::BATTLE_MAGE, ['3,2' => Item::createGiantRat()->defeatMonster()]);

        $this->expectException(InvalidMovementException::class);
        $this->expectExceptionMessage('There is no monster on that tile');
        $tester->handle($this->movement->lightningAttack(...), new LightningAttack($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString('3,2')));
    }

    #[Test]
    public function lightningAttackIsMarkulOnly(): void
    {
        $tester = $this->createTester(HeroType::WARLOCK, ['3,2' => Item::createGiantRat()]);

        $this->expectException(\DomainException::class);
        $tester->handle($this->movement->lightningAttack(...), new LightningAttack($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString('3,2')));
    }

    #[Test]
    public function hannaDeclaresASprintAtTheStartOfHerTurn(): void
    {
        [, $messages] = $this->createTester(HeroType::ACROBAT)->handle(
            $this->movement->declareSprint(...),
            new DeclareSprint($this->gameId, $this->playerId, $this->turnId),
        );

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::SPRINT, $action->action);
    }

    #[Test]
    public function sprintIsHannaOnlyAndOnlyFirst(): void
    {
        try {
            $this->createTester(HeroType::THIEF)->handle($this->movement->declareSprint(...), new DeclareSprint($this->gameId, $this->playerId, $this->turnId));
            self::fail('Only Hanna sprints');
        } catch (\DomainException) {
        }

        $this->expectException(InvalidMovementException::class);
        $this->createTester(HeroType::ACROBAT, [], [TurnAction::MOVE])
            ->handle($this->movement->declareSprint(...), new DeclareSprint($this->gameId, $this->playerId, $this->turnId));
    }

    #[Test]
    public function tirelessUnlocksTheMovementAfterAFight(): void
    {
        $locked = new \ReflectionProperty(Movement::class, 'hasMovedAfterBattle');
        $locked->setValue($this->movement, [$this->playerId->toString() => true]);

        $this->movement->onTurnActionPerformed(new TurnActionPerformed($this->turnId, $this->gameId, $this->playerId, TurnAction::MOVE, new \DateTimeImmutable()));
        self::assertTrue($locked->getValue($this->movement)[$this->playerId->toString()]);

        $this->movement->onTurnActionPerformed(new TurnActionPerformed($this->turnId, $this->gameId, $this->playerId, TurnAction::TIRELESS, new \DateTimeImmutable()));
        self::assertFalse($locked->getValue($this->movement)[$this->playerId->toString()]);
    }

    /**
     * @param array<string, Item> $items
     * @param list<TurnAction> $actionsBefore
     */
    private function createTester(?HeroType $hero, array $items = [], array $actionsBefore = []): MessageBusTester
    {
        $field = new class ($items) {
            /** @param array<string, Item> $items */
            public function __construct(private readonly array $items) {}

            /** @return array<string, Item> */
            public function getItems(): array
            {
                return $this->items;
            }
        };

        $player = new class ($hero) {
            public function __construct(private readonly ?HeroType $hero) {}

            public function getHP(): int
            {
                return 5;
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
