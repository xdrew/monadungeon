<?php

declare(strict_types=1);

namespace App\Tests\Game\Battle;

use App\Game\Battle\Battle;
use App\Game\Battle\BattleCompleted;
use App\Game\Battle\BattleResult;
use App\Game\Battle\FinalizeBattle;
use App\Game\Battle\StartBattle;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\ReducePlayerHP;
use App\Game\Turn\EndTurn;
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
 * Warlock "Sacrifice": after the roll the hero may pay 1 HP for +1 strength, once per combat.
 */
#[CoversClass(Battle::class)]
final class WarlockSacrificeTest extends TestCase
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
    public function sacrificingOneHpTurnsATieIntoAVictory(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $player = $this->createPlayer(HeroType::WARLOCK, hp: 3);
        $tester = $this->createTester($player);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()), // HP 5
        );

        // Weapons-only roll: 5 = 5 is a tie, the Warlock is offered the sacrifice
        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertTrue($completed->needsConsumableConfirmation);
        self::assertTrue($completed->canSacrificeHp, 'The Warlock must be offered Sacrifice');
        self::assertFalse($completed->hpSacrificed);
        self::assertSame(0, $this->hpReductions, 'Nothing is paid before the decision');

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(sacrificeHp: true),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result, '5 + 1 beats the giant rat');
        self::assertTrue($completed->hpSacrificed);
        self::assertSame(6, $completed->totalDamage);
        self::assertSame(5, $completed->diceRollDamage);
        self::assertSame(0, $completed->itemDamage, 'The sacrifice bonus is not an item bonus');
        self::assertSame(1, $this->hpReductions, 'The sacrifice costs exactly 1 HP');
        self::assertSame(2, $player->getHP());
        self::assertInstanceOf(EndTurn::class, $this->findMessage($messages, EndTurn::class), 'A finalized fight ends the turn (the reward is picked up by the same command)');

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertTrue($fight->additionalData['hpSacrificed'] ?? false);
        self::assertSame('sacrifice', $fight->additionalData['heroAbility'] ?? null);
        self::assertSame(6, $fight->additionalData['totalDamage'] ?? null);
        self::assertSame(0, $fight->additionalData['itemDamage'] ?? null);
    }

    #[Test]
    public function onlyTheWarlockCanSacrificeHp(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $player = $this->createPlayer(null, hp: 3);
        $tester = $this->createTester($player);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        self::assertFalse($this->lastBattleCompleted($messages)->canSacrificeHp);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only the Warlock');
        $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(sacrificeHp: true),
        );
    }

    #[Test]
    public function theLastHpCanBePaidForAVictory(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $player = $this->createPlayer(HeroType::WARLOCK, hp: 1);
        $tester = $this->createTester($player);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );
        self::assertTrue($this->lastBattleCompleted($messages)->canSacrificeHp, 'The last HP may be sacrificed');

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(sacrificeHp: true),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result);
        self::assertTrue($completed->hpSacrificed);
        self::assertSame(0, $player->getHP(), 'The hero is knocked out for his next turn');
        self::assertInstanceOf(EndTurn::class, $this->findMessage($messages, EndTurn::class));
    }

    #[Test]
    public function sacrificingTheLastHpAndLosingEndsTheTurn(): void
    {
        $this->field->setTestDiceRolls([1, 1]);
        $player = $this->createPlayer(HeroType::WARLOCK, hp: 1);
        $tester = $this->createTester($player);

        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()), // HP 15
        );

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(sacrificeHp: true),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::LOSE, $completed->result);
        self::assertTrue($completed->hpSacrificed);
        self::assertSame(3, $completed->totalDamage);
        self::assertSame(2, $this->hpReductions, 'The sacrifice and the loss are both paid');
        self::assertSame(0, $player->getHP(), 'HP never goes below 0');

        $reset = $this->findMessage($messages, ResetPlayerPosition::class);
        self::assertInstanceOf(ResetPlayerPosition::class, $reset);
        self::assertSame('0,0', $reset->position->toString(), 'The hero is moved back as usual');
        self::assertInstanceOf(EndTurn::class, $this->findMessage($messages, EndTurn::class));
    }

    #[Test]
    public function decliningTheSacrificeKeepsTheHp(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $player = $this->createPlayer(HeroType::WARLOCK, hp: 3);
        $tester = $this->createTester($player);

        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(sacrificeHp: false),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertFalse($completed->hpSacrificed);
        self::assertSame(5, $completed->totalDamage);
        self::assertSame(0, $this->hpReductions);
        self::assertSame(3, $player->getHP());

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertFalse($fight->additionalData['hpSacrificed'] ?? true);
        self::assertNull($fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function aWeaponsOnlyVictoryNeedsNoSacrifice(): void
    {
        $this->field->setTestDiceRolls([6, 6]);
        $tester = $this->createTester($this->createPlayer(HeroType::WARLOCK, hp: 3));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result);
        self::assertFalse($completed->needsConsumableConfirmation);
        self::assertFalse($completed->canSacrificeHp);
        self::assertFalse($completed->hpSacrificed);
        self::assertSame(0, $this->hpReductions);
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

    private function finalize(bool $sacrificeHp): FinalizeBattle
    {
        return new FinalizeBattle(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            selectedConsumableIds: [],
            pickupItem: false,
            sacrificeHp: $sacrificeHp,
        );
    }

    private function createPlayer(?HeroType $hero, int $hp): object
    {
        return new class($hero, $hp) {
            public function __construct(
                private readonly ?HeroType $hero,
                private int $hp,
            ) {}

            public function getHero(): ?HeroType
            {
                return $this->hero;
            }

            public function getHP(): int
            {
                return $this->hp;
            }

            public function loseHP(int $amount): void
            {
                $this->hp = max(0, $this->hp - $amount);
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

            /** @return list<Item> */
            public function getConsumableItems(): array
            {
                return [];
            }

            /**
             * @param list<string> $itemIds
             * @return list<Item>
             */
            public function getItemsByIds(array $itemIds): array
            {
                return [];
            }

            /**
             * @param list<string> $itemIds
             */
            public function calculateDamageFromItems(array $itemIds): int
            {
                return 0;
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
            function (ReducePlayerHP $command) use ($player): void {
                ++$this->hpReductions;
                $player->loseHP($command->amount);
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
