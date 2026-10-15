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
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\ReducePlayerHP;
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
use Telephantast\MessageBus\MessageContext;
use function App\Tests\Infrastructure\MessageBus\handle;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Oracle "Farseeing": +1 to the roll when the battle starts before any move of the turn was spent.
 */
#[CoversClass(Battle::class)]
final class OracleFarseeingTest extends TestCase
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
    public function oracleGetsPlusOneWhenFightingOnTheFirstMove(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $tester = $this->createTester($this->createPlayer(HeroType::ORACLE, []), movesSpent: 0);

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()), // HP 5
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result, '5 + 1 beats the giant rat right away');
        self::assertTrue($completed->farseeing);
        self::assertFalse($completed->needsConsumableConfirmation);
        self::assertSame(6, $completed->totalDamage);
        self::assertSame(5, $completed->diceRollDamage);
        self::assertSame(0, $completed->itemDamage, 'The Farseeing bonus is not an item bonus');

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertSame(TurnAction::FIGHT_MONSTER, $fight->action);
        self::assertTrue($fight->additionalData['farseeing'] ?? false);
        self::assertSame('farseeing', $fight->additionalData['heroAbility'] ?? null);
        self::assertSame(6, $fight->additionalData['totalDamage'] ?? null);
        self::assertSame(0, $fight->additionalData['itemDamage'] ?? null);
    }

    #[Test]
    public function noBonusOnceAMoveWasSpent(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $tester = $this->createTester($this->createPlayer(HeroType::ORACLE, []), movesSpent: 1);

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertTrue($completed->needsConsumableConfirmation);
        self::assertFalse($completed->farseeing);
        self::assertSame(5, $completed->totalDamage);
    }

    #[Test]
    public function otherHeroesDoNotGetTheBonus(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $tester = $this->createTester($this->createPlayer(null, []), movesSpent: 0);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertFalse($completed->farseeing);

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([]),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertFalse($completed->farseeing);
        self::assertSame(5, $completed->totalDamage);

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertFalse($fight->additionalData['farseeing'] ?? true);
        self::assertNull($fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function theBonusSurvivesTheFinalizationCommand(): void
    {
        $this->field->setTestDiceRolls([1, 2]);
        $fireball = Item::createMummy()->defeatMonster(); // the mummy's reward is the fireball (+1)
        $tester = $this->createTester($this->createPlayer(HeroType::ORACLE, [$fireball]), movesSpent: 0);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::LOSE, $completed->result, '3 + 1 is still short of 5');
        self::assertTrue($completed->farseeing);
        self::assertSame(4, $completed->totalDamage);
        self::assertTrue($completed->needsConsumableConfirmation);

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([$fireball->itemId->toString()]),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result, '3 + 1 (fireball) + 1 (Farseeing) = 5');
        self::assertTrue($completed->farseeing);
        self::assertSame(5, $completed->totalDamage);
        self::assertSame(3, $completed->diceRollDamage);
        self::assertSame(1, $completed->itemDamage);

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertTrue($fight->additionalData['farseeing'] ?? false);
        self::assertSame('farseeing', $fight->additionalData['heroAbility'] ?? null);
        self::assertSame(5, $fight->additionalData['totalDamage'] ?? null);
        self::assertSame(1, $fight->additionalData['itemDamage'] ?? null);
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

    /**
     * @param list<string> $selectedConsumableIds
     */
    private function finalize(array $selectedConsumableIds): FinalizeBattle
    {
        return new FinalizeBattle(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            selectedConsumableIds: $selectedConsumableIds,
            pickupItem: false,
        );
    }

    /**
     * A started turn with the given number of MOVE actions already counted.
     */
    private function createTurn(int $movesSpent): GameTurn
    {
        $at = new \DateTimeImmutable();
        [$turn] = handle(GameTurn::start(...), new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $at,
        ));
        for ($i = 0; $i < $movesSpent; ++$i) {
            $turn->updateActionCounter(new TurnActionPerformed(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                action: TurnAction::MOVE,
                performedAt: $at,
            ));
        }

        return $turn;
    }

    /**
     * @param list<Item> $spells
     */
    private function createPlayer(?HeroType $hero, array $spells): object
    {
        return new class ($hero, $spells) {
            /**
             * @param list<Item> $spells
             */
            public function __construct(
                private readonly ?HeroType $hero,
                private readonly array $spells,
            ) {}

            public function getHero(): ?HeroType
            {
                return $this->hero;
            }

            public function getSkills(): ?\App\Game\Player\HeroSkills
            {
                $hero = $this->getHero();

                return $hero instanceof \App\Game\Player\HeroType ? \App\Game\Player\HeroSkills::of($hero) : null;
            }

            public function getHP(): int
            {
                return 3;
            }

            public function loseHP(int $amount): void {}

            public function isAwaitingReincarnation(): bool
            {
                return false;
            }

            /** @return list<Item> */
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
                return $this->spells;
            }

            /**
             * @param list<string> $itemIds
             * @return list<Item>
             */
            public function getItemsByIds(array $itemIds): array
            {
                return array_values(array_filter(
                    $this->spells,
                    static fn(Item $spell): bool => \in_array($spell->itemId->toString(), $itemIds, true),
                ));
            }

            /**
             * @param list<string> $itemIds
             */
            public function calculateDamageFromItems(array $itemIds): int
            {
                $damage = 0;
                foreach ($this->getItemsByIds($itemIds) as $item) {
                    $damage += $item->type->getDamage();
                }

                return $damage;
            }
        };
    }

    private function createTester(object $player, int $movesSpent): MessageBusTester
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
        $turn = $this->createTurn($movesSpent);

        return MessageBusTester::create(
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetTurn $_query): GameTurn => $turn,
            static fn(GetPlayer $_query): object => $player,
            fn(GetField $_query): Field => $this->field,
            static fn(GetGame $_query): object => $game,
            static function (ReducePlayerHP $command) use ($player): void {
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
