<?php

declare(strict_types=1);

namespace App\Tests\Game\Battle;

use App\Game\Battle\Battle;
use App\Game\Battle\BattleCompleted;
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
use App\Game\Player\HeroSkills;
use App\Game\Player\HeroType;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\RemoveItemFromInventory;
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
 * A fight is resolved once (a repeated finalize costs no second HP and spends nothing), and only the hero's attack
 * spells add to it: any other id sent as a "consumable" is ignored and stays in the pack.
 */
#[CoversClass(Battle::class)]
final class FinalizeBattleOnceTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    private Uuid $battleId;

    private Field $field;

    private Item $fireball;

    private int $hpLost = 0;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->battleId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
        // The mummy's reward is the fireball ("magic bolt")
        $this->fireball = Item::createMummy()->defeatMonster();
    }

    #[Test]
    public function aRepeatedFinalizeAfterALossCostsNoSecondHp(): void
    {
        $this->field->setTestDiceRolls([1, 1]);
        $tester = $this->createTester($this->createPlayer(null, []));
        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $tester->handle(static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context), $this->finalize([]));
        self::assertSame(1, $this->hpLost, 'The lost fight costs one HP');

        [, $again] = $tester->handle(static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context), $this->finalize([]));
        self::assertSame(1, $this->hpLost, 'Repeating the request costs no second HP');
        self::assertSame([], array_values(array_filter($again, static fn(object $m): bool => $m instanceof BattleCompleted || $m instanceof PerformTurnAction)));
    }

    #[Test]
    public function aWeaponSentAsASpellAddsNothingAndStaysInThePack(): void
    {
        $this->field->setTestDiceRolls([2, 2]);
        $sword = Item::createSkeletonWarrior()->defeatMonster();
        $fireball = Item::createMummy()->defeatMonster();
        $tester = $this->createTester($this->createPlayer(null, [$fireball], [$sword]));
        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([$sword->itemId->toString(), $fireball->itemId->toString()]),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(5, $completed->totalDamage, '4 + the fireball; the sword id adds nothing');
        $removed = array_values(array_filter($messages, static fn(object $m): bool => $m instanceof RemoveItemFromInventory));
        self::assertCount(1, $removed);
        self::assertTrue($removed[0]->itemId->equals($fireball->itemId), 'Only the fireball is spent');
    }

    #[Test]
    public function anotherPlayerCannotFinalizeTheFight(): void
    {
        $this->field->setTestDiceRolls([1, 1]);
        $tester = $this->createTester($this->createPlayer(null, []));
        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        $this->expectException(\DomainException::class);
        $tester->handle(static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context), new FinalizeBattle(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: Uuid::v7(),
            turnId: $this->turnId,
            selectedConsumableIds: [],
        ));
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
     * @param list<Item> $spells
     */
    private function createPlayer(?HeroType $hero, array $spells, array $weapons = []): object
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

            public function getSkills(): ?HeroSkills
            {
                $hero = $this->getHero();

                return $hero instanceof HeroType ? HeroSkills::of($hero) : null;
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
                    [...$this->spells, ...$this->weapons],
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
            function (ReducePlayerHP $command): void {
                $this->hpLost += $command->amount;
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
