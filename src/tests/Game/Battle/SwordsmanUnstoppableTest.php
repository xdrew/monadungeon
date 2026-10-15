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
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\RemoveItemFromInventory;
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
 * Swordsman "Unstoppable": a 6 on the dice keeps the turn going after the combat, whatever its result.
 */
#[CoversClass(Battle::class)]
final class SwordsmanUnstoppableTest extends TestCase
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
    public function aLostFightWithASixKeepsTheTurnGoing(): void
    {
        $this->field->setTestDiceRolls([6, 2]);
        $player = $this->createPlayer(HeroType::SWORDSMAN, hp: 3);
        $tester = $this->createTester($player);

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()), // HP 15
        );

        // Weapons-only roll: the player is asked about consumables, the turn is already known to continue
        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::LOSE, $completed->result);
        self::assertTrue($completed->needsConsumableConfirmation);
        self::assertTrue($completed->unstoppable);
        self::assertNull($this->findMessage($messages, EndTurn::class));
    }

    #[Test]
    public function finalizingALostFightWithASixCostsHpButNotTheTurn(): void
    {
        $this->field->setTestDiceRolls([6, 2]);
        $player = $this->createPlayer(HeroType::SWORDSMAN, hp: 3);
        $tester = $this->createTester($player);

        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()),
        );

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::LOSE, $completed->result);
        self::assertTrue($completed->unstoppable);
        self::assertSame(2, $player->getHP(), 'The loss still costs 1 HP');

        $reset = $this->findMessage($messages, ResetPlayerPosition::class);
        self::assertInstanceOf(ResetPlayerPosition::class, $reset);
        self::assertSame('0,0', $reset->position->toString(), 'The hero is moved back as usual');

        self::assertNull($this->findMessage($messages, EndTurn::class), 'Unstoppable: the turn goes on');

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertTrue($fight->additionalData['unstoppable'] ?? false);
        self::assertSame('unstoppable', $fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function withoutASixTheTurnEndsAsUsual(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $player = $this->createPlayer(HeroType::SWORDSMAN, hp: 3);
        $tester = $this->createTester($player);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()),
        );
        self::assertFalse($this->lastBattleCompleted($messages)->unstoppable);

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::LOSE, $completed->result);
        self::assertFalse($completed->unstoppable);
        self::assertInstanceOf(EndTurn::class, $this->findMessage($messages, EndTurn::class));

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertFalse($fight->additionalData['unstoppable'] ?? true);
        self::assertNull($fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function aKnockedOutSwordsmanDoesNotContinueEvenWithASix(): void
    {
        $this->field->setTestDiceRolls([6, 2]);
        $player = $this->createPlayer(HeroType::SWORDSMAN, hp: 1);
        $tester = $this->createTester($player);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()),
        );
        self::assertFalse($this->lastBattleCompleted($messages)->unstoppable, 'Losing the last HP knocks him out');

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(),
        );

        self::assertSame(0, $player->getHP());
        self::assertFalse($this->lastBattleCompleted($messages)->unstoppable);
        self::assertInstanceOf(EndTurn::class, $this->findMessage($messages, EndTurn::class));
    }

    #[Test]
    public function aVictoryWithConsumablesAndASixDoesNotEndTheTurn(): void
    {
        $this->field->setTestDiceRolls([6, 2]);
        // A fireball spell in the inventory (dropped by a defeated mummy)
        $fireball = new Item(name: ItemName::MUMMY, type: ItemType::FIREBALL, guardHP: 0, guardDefeated: true, itemId: Uuid::v7());
        $player = $this->createPlayer(HeroType::SWORDSMAN, hp: 3, consumables: [$fireball]);
        $tester = $this->createTester($player);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createSkeletonTurnkey()), // HP 8: 8 is a tie, 8 + 1 wins
        );
        self::assertSame(BattleResult::DRAW, $this->lastBattleCompleted($messages)->result);

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([$fireball->itemId->toString()]),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result);
        self::assertTrue($completed->unstoppable);
        self::assertSame(3, $player->getHP());
        self::assertInstanceOf(RemoveItemFromInventory::class, $this->findMessage($messages, RemoveItemFromInventory::class));
        self::assertNull($this->findMessage($messages, EndTurn::class), 'The Swordsman may keep moving after the win');
    }

    #[Test]
    public function otherPlayersEndTheirTurnAfterALossWithSixes(): void
    {
        $this->field->setTestDiceRolls([6, 6]);
        $player = $this->createPlayer(null, hp: 3);
        $tester = $this->createTester($player);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createDragon()),
        );
        self::assertFalse($this->lastBattleCompleted($messages)->unstoppable);

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(),
        );

        self::assertInstanceOf(EndTurn::class, $this->findMessage($messages, EndTurn::class));
    }

    #[Test]
    public function aTieWithASixMovesHimBackButKeepsTheTurn(): void
    {
        $this->field->setTestDiceRolls([6, 4]);
        $player = $this->createPlayer(HeroType::SWORDSMAN, hp: 3);
        $tester = $this->createTester($player);

        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createSkeletonKing()), // HP 10
        );

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertTrue($completed->unstoppable);
        self::assertSame(3, $player->getHP(), 'A tie costs no HP');
        self::assertInstanceOf(ResetPlayerPosition::class, $this->findMessage($messages, ResetPlayerPosition::class));
        self::assertNull($this->findMessage($messages, EndTurn::class));
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
    private function finalize(array $selectedConsumableIds = []): FinalizeBattle
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
     * @param list<Item> $consumables
     */
    private function createPlayer(?HeroType $hero, int $hp, array $consumables = []): object
    {
        return new class($hero, $hp, $consumables) {
            /**
             * @param list<Item> $consumables
             */
            public function __construct(
                private readonly ?HeroType $hero,
                private int $hp,
                private readonly array $consumables,
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
                return $this->consumables;
            }

            /**
             * @param list<string> $itemIds
             * @return list<Item>
             */
            public function getItemsByIds(array $itemIds): array
            {
                return array_values(array_filter(
                    $this->consumables,
                    static fn(Item $item): bool => \in_array($item->itemId->toString(), $itemIds, true),
                ));
            }

            /**
             * @param list<string> $itemIds
             */
            public function calculateDamageFromItems(array $itemIds): int
            {
                return \count($this->getItemsByIds($itemIds));
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
