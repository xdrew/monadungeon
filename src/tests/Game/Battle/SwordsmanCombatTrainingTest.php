<?php

declare(strict_types=1);

namespace App\Tests\Game\Battle;

use App\Game\Battle\Battle;
use App\Game\Battle\BattleCompleted;
use App\Game\Battle\BattleResult;
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
 * Swordsman "Combat Training": every die showing 1 is rerolled until it shows something better.
 */
#[CoversClass(Battle::class)]
final class SwordsmanCombatTrainingTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    private Uuid $battleId;

    private Field $field;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->battleId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
    }

    #[Test]
    public function onesAreRerolledUntilTheyShowSomethingBetter(): void
    {
        // Both dice show 1; the first is rerolled to 4, the second is rerolled to 1 again and then to 5
        $this->field->setTestDiceRolls([1, 1, 4, 1, 5]);
        $tester = $this->createTester($this->createPlayer(HeroType::SWORDSMAN));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()), // HP 5
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame([4, 5], $completed->diceResults);
        self::assertSame(3, $completed->onesRerolled);
        self::assertSame(9, $completed->diceRollDamage);
        self::assertSame(BattleResult::WIN, $completed->result);
        self::assertFalse($completed->needsConsumableConfirmation);

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertSame(3, $fight->additionalData['onesRerolled'] ?? null);
        self::assertSame('combat_training', $fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function nothingIsRerolledWithoutOnes(): void
    {
        $this->field->setTestDiceRolls([3, 4]);
        $tester = $this->createTester($this->createPlayer(HeroType::SWORDSMAN));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()), // HP 15
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame([3, 4], $completed->diceResults);
        self::assertSame(0, $completed->onesRerolled);
        self::assertSame(BattleResult::LOSE, $completed->result);
        self::assertTrue($completed->needsConsumableConfirmation);
    }

    #[Test]
    public function playersWithoutTheSkillKeepTheirOnes(): void
    {
        $this->field->setTestDiceRolls([1, 1]);
        $tester = $this->createTester($this->createPlayer(null));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame([1, 1], $completed->diceResults);
        self::assertSame(0, $completed->onesRerolled);
    }

    #[Test]
    public function theWarriorKeepsHisOnesToo(): void
    {
        $this->field->setTestDiceRolls([1, 2]);
        $tester = $this->createTester($this->createPlayer(HeroType::WARRIOR));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame([1, 2], $completed->diceResults);
        self::assertSame(0, $completed->onesRerolled);
        self::assertTrue($completed->canReroll, 'Double Attack is a different skill');
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

    private function createPlayer(?HeroType $hero): object
    {
        return new class ($hero) {
            public function __construct(private readonly ?HeroType $hero) {}

            public function getHero(): ?HeroType
            {
                return $this->hero;
            }

            public function getHP(): int
            {
                return 5;
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
        $game = new class () {
            public function getStatus(): object
            {
                return new class () {
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
            static function (ReducePlayerHP $_command): void {},
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
