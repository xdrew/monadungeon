<?php

declare(strict_types=1);

namespace App\Tests\Game\Battle;

use App\Game\Battle\Battle;
use App\Game\Battle\BattleCompleted;
use App\Game\Battle\BattleFinalized;
use App\Game\Battle\BattleResult;
use App\Game\Battle\FinalizeBattle;
use App\Game\Battle\MummyDefeated;
use App\Game\Battle\StartBattle;
use App\Game\Duel\DuelSide;
use App\Game\Duel\DuelSideCompleted;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Turn\EndTurn;
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
 * Karak: Regent — a Battle fought as one side of a Battle Arena duel; Frost Fist (+2) as a battle consumable.
 */
#[CoversClass(Battle::class)]
#[CoversClass(Field::class)]
final class DuelBattleTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $rivalId;

    private Uuid $turnId;

    private Uuid $battleId;

    private Uuid $duelId;

    private Field $field;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->rivalId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->battleId = Uuid::v7();
        $this->duelId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
    }

    #[Test]
    public function theChallengerAlwaysDecidesAndLocksInHisStrength(): void
    {
        $this->field->setTestDiceRolls([3, 4]);
        $frostFist = Item::createSkeletonMage();
        $sword = Item::createSkeletonWarrior();
        $tester = $this->createTester($this->createPlayer(null, [$frostFist], [$sword]));

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(DuelSide::CHALLENGER, null),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertTrue($completed->needsConsumableConfirmation, 'Even a strong roll waits for the challenger');
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertSame(0, $completed->monsterHP);
        self::assertSame(9, $completed->totalDamage);
        self::assertSame(['duelId' => $this->duelId->toString(), 'role' => 'challenger', 'opponentId' => $this->rivalId->toString(), 'toBeat' => null], $completed->duel);
        self::assertNull($this->findMessage($messages, PerformTurnAction::class));
        self::assertNull($this->findMessage($messages, DuelSideCompleted::class));

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([$frostFist->itemId->toString()]),
        );

        self::assertInstanceOf(BattleFinalized::class, $this->findMessage($messages, BattleFinalized::class));
        $removed = $this->findMessage($messages, RemoveItemFromInventory::class);
        self::assertInstanceOf(RemoveItemFromInventory::class, $removed);
        self::assertTrue($removed->itemId->equals($frostFist->itemId));
        $side = $this->findMessage($messages, DuelSideCompleted::class);
        self::assertInstanceOf(DuelSideCompleted::class, $side);
        self::assertSame(11, $side->total, '3 + 4 + sword 2 + Frost Fist 2');
        self::assertSame(4, $side->itemDamage);
        self::assertSame('challenger', $side->role);
        self::assertTrue($side->duelId->equals($this->duelId));
        foreach ([EndTurn::class, PerformTurnAction::class, MummyDefeated::class] as $class) {
            self::assertNull($this->findMessage($messages, $class), $class . ' does not belong to a duel');
        }

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([]),
        );
        self::assertSame([], $messages, 'A side is locked in once');
    }

    #[Test]
    public function theSummonedHeroBeatingTheTotalWithWeaponsIsDoneAtOnce(): void
    {
        $this->field->setTestDiceRolls([5, 5]);
        $tester = $this->createTester($this->createPlayer(null, [], []));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(DuelSide::OPPONENT, 9),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result);
        self::assertSame(9, $completed->duel['toBeat'] ?? null);
        $side = $this->findMessage($messages, DuelSideCompleted::class);
        self::assertInstanceOf(DuelSideCompleted::class, $side);
        self::assertSame(10, $side->total);
        self::assertSame('opponent', $side->role);
        self::assertNull($this->findMessage($messages, PerformTurnAction::class));
    }

    #[Test]
    public function anAutomaticSideSpendsTheFewestSpellsThatWin(): void
    {
        self::assertSame(['frost'], $this->autoPick(toBeat: 5), '4 + 2 beats 5');
        self::assertSame(['frost', 'fireball'], $this->autoPick(toBeat: 6), '4 + 2 + 1 beats 6');
        self::assertSame([], $this->autoPick(toBeat: 12), 'Nothing helps: keep the spells');
        self::assertSame([], $this->autoPick(toBeat: 3), 'The roll alone wins');
    }

    #[Test]
    public function aSummonedOracleGetsTheFarseeingBonusFromTheDuel(): void
    {
        $this->field->setTestDiceRolls([2, 2]);
        $tester = $this->createTester($this->createPlayer(HeroType::ORACLE, [], []), movesSpent: 3);

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(DuelSide::OPPONENT, 9, farseeing: true),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertTrue($completed->farseeing);
        self::assertSame(5, $completed->totalDamage);
    }

    #[Test]
    public function theWizardKeepsTheFrostFistInAMonsterFight(): void
    {
        $this->field->setTestDiceRolls([2, 2]);
        $frostFist = Item::createSkeletonMage();
        $tester = $this->createTester($this->createPlayer(HeroType::WIZARD, [$frostFist], []));

        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            new StartBattle($this->battleId, $this->gameId, $this->playerId, $this->turnId, Item::createGiantRat(), FieldPlace::fromString('0,0'), FieldPlace::fromString('1,0')),
        );
        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([$frostFist->itemId->toString()]),
        );

        $finalized = $this->findMessage($messages, BattleFinalized::class);
        self::assertInstanceOf(BattleFinalized::class, $finalized);
        self::assertSame([$frostFist->itemId->toString()], $finalized->keptConsumableIds);
        self::assertNull($this->findMessage($messages, RemoveItemFromInventory::class));
        self::assertSame(BattleResult::WIN, $this->lastBattleCompleted($messages)->result, '2 + 2 + 2 beats the giant rat');
    }

    #[Test]
    public function aFrostFistCountsTowardsAPotentialVictory(): void
    {
        $rat = Item::createGiantRat();
        (new \ReflectionProperty(Field::class, 'items'))->setValue($this->field, ['1,0' => $rat]);
        $tester = MessageBusTester::create(
            static fn(GetPlayerPosition $_query): FieldPlace => FieldPlace::fromString('1,0'),
        );

        $tester->handle($this->field->onBattleCompleted(...), new BattleCompleted(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            result: BattleResult::LOSE,
            diceResults: [2, 2],
            diceRollDamage: 4,
            itemDamage: 0,
            totalDamage: 4,
            monsterHP: 5,
            availableConsumables: [Item::createSkeletonMage()],
            needsConsumableConfirmation: true,
        ));

        self::assertTrue($this->field->getLastBattleInfo()['reward']['isPotentialReward'] ?? false, '4 + Frost Fist 2 beats 5');
    }

    /**
     * @return list<string> 'frost' / 'fireball' for the spells the automatic side spent
     */
    private function autoPick(int $toBeat): array
    {
        $this->battleId = Uuid::v7();
        $this->field->setTestDiceRolls([2, 2]);
        $frostFist = Item::createSkeletonMage();
        $fireball = Item::createMummy();
        $tester = $this->createTester($this->createPlayer(null, [$fireball, $frostFist], []));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(DuelSide::OPPONENT, $toBeat, auto: true),
        );

        self::assertInstanceOf(DuelSideCompleted::class, $this->findMessage($messages, DuelSideCompleted::class), 'An automatic side completes by itself');
        $names = [$frostFist->itemId->toString() => 'frost', $fireball->itemId->toString() => 'fireball'];
        $finalized = $this->findMessage($messages, BattleFinalized::class);

        return array_map(static fn(string $id): string => $names[$id], $finalized?->selectedConsumableIds ?? []);
    }

    private function startBattle(string $role, ?int $toBeat, bool $farseeing = false, bool $auto = false): StartBattle
    {
        $arena = FieldPlace::fromString('1,0');

        return new StartBattle(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            monster: Item::createDuelTarget($toBeat ?? 0),
            fromPosition: $arena,
            toPosition: $arena,
            duel: new DuelSide($this->duelId, $role, $toBeat, $farseeing, $auto, $this->rivalId),
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
        );
    }

    /**
     * @param list<Item> $spells
     * @param list<Item> $weapons
     */
    private function createPlayer(?HeroType $hero, array $spells, array $weapons): object
    {
        return new class ($hero, $spells, $weapons) {
            /**
             * @param list<Item> $spells
             * @param list<Item> $weapons
             */
            public function __construct(
                private readonly ?HeroType $hero,
                private readonly array $spells,
                private readonly array $weapons,
            ) {}

            public function getHero(): ?HeroType
            {
                return $this->hero;
            }

            public function getHP(): int
            {
                return 3;
            }

            public function isAi(): bool
            {
                return false;
            }

            public function isAwaitingReincarnation(): bool
            {
                return false;
            }

            /** @return list<Item> */
            public function getWeaponItems(): array
            {
                return $this->weapons;
            }

            public function calculateWeaponDamage(): int
            {
                return array_sum(array_map(static fn(Item $w): int => $w->type->getDamage(), $this->weapons));
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
                return array_sum(array_map(static fn(Item $i): int => $i->type->getDamage(), $this->getItemsByIds($itemIds)));
            }
        };
    }

    private function createTester(object $player, int $movesSpent = 0): MessageBusTester
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
        $at = new \DateTimeImmutable();
        [$turn] = handle(GameTurn::start(...), new StartTurn($this->gameId, $this->playerId, 1, $this->turnId, $at));
        for ($i = 0; $i < $movesSpent; ++$i) {
            $turn->updateActionCounter(new TurnActionPerformed($this->turnId, $this->gameId, $this->playerId, TurnAction::MOVE, $at));
        }

        return MessageBusTester::create(
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetTurn $_query): GameTurn => $turn,
            static fn(GetPlayer $_query): object => $player,
            fn(GetField $_query): Field => $this->field,
            static fn(GetGame $_query): object => $game,
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
