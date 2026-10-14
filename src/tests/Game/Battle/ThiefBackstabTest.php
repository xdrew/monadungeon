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
 * Thief "Backstab": a combat that would be a tie is a victory.
 */
#[CoversClass(Battle::class)]
final class ThiefBackstabTest extends TestCase
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
    public function thiefWinsATieOnTheFirstRoll(): void
    {
        $this->field->setTestDiceRolls([2, 3]); // 5 = giant rat HP
        $tester = $this->createTester($this->createPlayer(HeroType::THIEF));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result, 'A tie is a Backstab victory');
        self::assertTrue($completed->tieIsWin);
        self::assertFalse($completed->needsConsumableConfirmation, 'The win is final, no consumable step');
        self::assertSame(0, $this->hpReductions);

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertSame('WIN', $fight->additionalData['result'] ?? null);
        self::assertTrue($fight->additionalData['backstab'] ?? false);
        self::assertSame('backstab', $fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function playerWithoutHeroDrawsOnEqualDamage(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $tester = $this->createTester($this->createPlayer(null));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertFalse($completed->tieIsWin);
        self::assertTrue($completed->needsConsumableConfirmation);
    }

    #[Test]
    public function thiefStillLosesWhenDamageIsLower(): void
    {
        $this->field->setTestDiceRolls([1, 2]);
        $tester = $this->createTester($this->createPlayer(HeroType::THIEF));

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::LOSE, $completed->result);
        self::assertTrue($completed->tieIsWin, 'The flag describes the hero, not the roll');
        self::assertFalse($completed->canReroll, 'Only the Warrior rerolls');
    }

    #[Test]
    public function thiefWinsATieReachedWithConsumables(): void
    {
        $this->field->setTestDiceRolls([1, 3]); // 4 < 5: lose on the first roll, +1 fireball makes a tie
        // A fireball spell in the inventory (dropped by a defeated mummy)
        $fireball = new Item(name: ItemName::MUMMY, type: ItemType::FIREBALL, guardHP: 0, guardDefeated: true, itemId: Uuid::v7());
        $player = $this->createPlayer(HeroType::THIEF, consumables: [$fireball]);
        $tester = $this->createTester($player);

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );
        self::assertSame(BattleResult::LOSE, $this->lastBattleCompleted($messages)->result);

        [, $finalMessages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            new FinalizeBattle(
                battleId: $this->battleId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                turnId: $this->turnId,
                selectedConsumableIds: [$fireball->itemId->toString()],
            ),
        );

        $completed = $this->lastBattleCompleted($finalMessages);
        self::assertSame(5, $completed->totalDamage);
        self::assertSame(BattleResult::WIN, $completed->result, '4 + 1 = 5 ties the rat, Backstab makes it a win');
        self::assertSame(0, $this->hpReductions, 'A Backstab win costs no HP');
        self::assertInstanceOf(EndTurn::class, $this->findMessage($finalMessages, EndTurn::class), 'Finalizing with consumables ends the turn (pickup is part of the same command)');
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
     * @param list<Item> $consumables
     */
    private function createPlayer(?HeroType $hero, array $consumables = []): object
    {
        return new class($hero, $consumables) {
            /**
             * @param list<Item> $consumables
             */
            public function __construct(private readonly ?HeroType $hero, private readonly array $consumables) {}

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
                return $this->consumables;
            }

            /**
             * @param list<string> $ids
             */
            public function getItemsByIds(array $ids): array
            {
                return array_values(array_filter($this->consumables, static fn(Item $item): bool => \in_array($item->itemId->toString(), $ids, true)));
            }

            /**
             * @param list<string> $ids
             */
            public function calculateDamageFromItems(array $ids): int
            {
                $damage = 0;
                foreach ($this->getItemsByIds($ids) as $item) {
                    $damage += $item->type->getDamage();
                }

                return $damage;
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
