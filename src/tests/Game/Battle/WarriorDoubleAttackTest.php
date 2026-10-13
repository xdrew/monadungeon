<?php

declare(strict_types=1);

namespace App\Tests\Game\Battle;

use App\Game\Battle\Battle;
use App\Game\Battle\BattleCompleted;
use App\Game\Battle\BattleDiceRerolled;
use App\Game\Battle\BattleResult;
use App\Game\Battle\RerollBattleDice;
use App\Game\Battle\StartBattle;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\ReducePlayerHP;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\PerformTurnAction;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Telephantast\MessageBus\MessageContext;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Warrior "Double Attack": reroll the dice once per battle, the second roll must be accepted.
 */
#[CoversClass(Battle::class)]
final class WarriorDoubleAttackTest extends TestCase
{
    private Uuid $gameId;
    private Uuid $playerId;
    private Uuid $turnId;
    private Uuid $battleId;
    private Field $field;
    private int $hpReductions = 0;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->battleId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
        $this->hpReductions = 0;
    }

    #[Test]
    public function warriorCanRerollAfterLosingFirstRoll(): void
    {
        $this->field->setTestDiceRolls([1, 1, 6, 6]);
        $tester = $this->createTester($this->createPlayer(HeroType::WARRIOR));

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()), // HP 5
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::LOSE, $completed->result);
        self::assertSame([1, 1], $completed->diceResults);
        self::assertTrue($completed->needsConsumableConfirmation);
        self::assertTrue($completed->canReroll, 'Warrior must be offered Double Attack');
        self::assertFalse($completed->rerolled);
        self::assertNull($completed->firstDiceResults);
        self::assertSame(0, $this->hpReductions, 'The first roll must not cost HP');

        [, $rerollMessages] = $tester->handle(
            static fn(RerollBattleDice $command, MessageContext $context) => $battle->rerollDice($command, $context),
            $this->reroll(),
        );

        $rerolled = $this->findMessage($rerollMessages, BattleDiceRerolled::class);
        self::assertInstanceOf(BattleDiceRerolled::class, $rerolled);
        self::assertSame([1, 1], $rerolled->firstDiceResults);
        self::assertSame([6, 6], $rerolled->diceResults);

        $completed = $this->lastBattleCompleted($rerollMessages);
        self::assertSame(BattleResult::WIN, $completed->result, '6 + 6 beats the giant rat');
        self::assertSame([6, 6], $completed->diceResults);
        self::assertTrue($completed->rerolled);
        self::assertSame([1, 1], $completed->firstDiceResults);
        self::assertFalse($completed->canReroll, 'The second roll must be accepted');
        self::assertFalse($completed->needsConsumableConfirmation);
        self::assertSame(0, $this->hpReductions);

        $fight = $this->findMessage($rerollMessages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertTrue($fight->additionalData['rerolled'] ?? false);
        self::assertSame('double_attack', $fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function secondRollMustBeAcceptedEvenWhenItLoses(): void
    {
        $this->field->setTestDiceRolls([2, 2, 1, 2]);
        $tester = $this->createTester($this->createPlayer(HeroType::WARRIOR));

        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()), // HP 15
        );

        [, $rerollMessages] = $tester->handle(
            static fn(RerollBattleDice $command, MessageContext $context) => $battle->rerollDice($command, $context),
            $this->reroll(),
        );

        $completed = $this->lastBattleCompleted($rerollMessages);
        self::assertSame(BattleResult::LOSE, $completed->result);
        self::assertSame([1, 2], $completed->diceResults);
        self::assertTrue($completed->rerolled);
        self::assertFalse($completed->canReroll);
        self::assertTrue($completed->needsConsumableConfirmation, 'Consumables can still be used after the reroll');
        self::assertSame(0, $this->hpReductions, 'HP is only lost once the result is finalized');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already rerolled');
        $tester->handle(
            static fn(RerollBattleDice $command, MessageContext $context) => $battle->rerollDice($command, $context),
            $this->reroll(),
        );
    }

    #[Test]
    public function playerWithoutHeroCannotReroll(): void
    {
        $this->field->setTestDiceRolls([1, 1, 6, 6]);
        $tester = $this->createTester($this->createPlayer(null));

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::LOSE, $completed->result);
        self::assertFalse($completed->canReroll);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only the Warrior');
        $tester->handle(
            static fn(RerollBattleDice $command, MessageContext $context) => $battle->rerollDice($command, $context),
            $this->reroll(),
        );
    }

    #[Test]
    public function winningFirstRollIsFinalWithoutRerollOffer(): void
    {
        $this->field->setTestDiceRolls([6, 6]);
        $tester = $this->createTester($this->createPlayer(HeroType::WARRIOR));

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result);
        self::assertFalse($completed->canReroll);
        self::assertFalse($completed->rerolled);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already completed');
        $tester->handle(
            static fn(RerollBattleDice $command, MessageContext $context) => $battle->rerollDice($command, $context),
            $this->reroll(),
        );
    }

    private function startBattle(Item $monster): StartBattle
    {
        return new StartBattle(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            monster: $monster,
            fromPosition: FieldPlace::fromString('0,0'),
            toPosition: FieldPlace::fromString('1,0'),
        );
    }

    private function reroll(): RerollBattleDice
    {
        return new RerollBattleDice(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
        );
    }

    private function createPlayer(?HeroType $hero): object
    {
        return new class($hero) {
            public function __construct(private readonly ?HeroType $hero) {}

            public function getHero(): ?HeroType
            {
                return $this->hero;
            }

            public function isAwaitingReincarnation(): bool
            {
                return false;
            }

            public function getWeaponItems(): array
            {
                return [];
            }

            public function calculateWeaponDamage(): int
            {
                return 0;
            }

            public function getConsumableItems(): array
            {
                return [];
            }
        };
    }

    private function createTester(object $player): MessageBusTester
    {
        $game = new class {
            public function getStatus(): object
            {
                return new class {
                    public function isFinished(): bool
                    {
                        return false;
                    }
                };
            }
        };

        return MessageBusTester::create(
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetPlayer $_query): object => $player,
            fn(GetField $_query): Field => $this->field,
            static fn(GetGame $_query): object => $game,
            // Commands/events without handlers are recorded by the tester and can be inspected
            function (ReducePlayerHP $_command): void {
                ++$this->hpReductions;
            },
        );
    }

    /**
     * @param list<object> $messages
     */
    private function lastBattleCompleted(array $messages): BattleCompleted
    {
        $completed = null;
        foreach ($messages as $message) {
            if ($message instanceof BattleCompleted) {
                $completed = $message;
            }
        }
        self::assertInstanceOf(BattleCompleted::class, $completed, 'Expected BattleCompleted event');

        return $completed;
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
