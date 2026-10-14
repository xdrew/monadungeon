<?php

declare(strict_types=1);

namespace App\Tests\Game\Battle;

use App\Game\Battle\Battle;
use App\Game\Battle\BattleCompleted;
use App\Game\Battle\BattleFinalized;
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
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\ItemPlacedOnField;
use App\Game\Player\ItemRemovedFromInventory;
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
 * Wizard "Magical Affinity": fireballs used in combat still add +1 each but stay in the inventory.
 */
#[CoversClass(Battle::class)]
#[CoversClass(Field::class)]
final class WizardMagicalAffinityTest extends TestCase
{
    private Uuid $gameId;
    private Uuid $playerId;
    private Uuid $turnId;
    private Uuid $battleId;
    private Field $field;
    private Item $fireball;

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
    public function wizardKeepsTheFireballAfterUsingIt(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $tester = $this->createTester($this->createPlayer(HeroType::WIZARD, [$this->fireball]));

        [$battle, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()), // HP 5
        );

        // Weapons-only roll: 5 = 5 is a tie, the fireball is offered
        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertTrue($completed->needsConsumableConfirmation);
        self::assertCount(1, $completed->availableConsumables ?? []);
        self::assertFalse($completed->spellsKept);

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([$this->fireball->itemId->toString()]),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result, '5 + 1 beats the giant rat');
        self::assertSame(6, $completed->totalDamage);
        self::assertSame(1, $completed->itemDamage, 'The fireball still adds its bonus');
        self::assertTrue($completed->spellsKept);
        self::assertNull($this->findMessage($messages, RemoveItemFromInventory::class), 'The fireball is not consumed');
        self::assertInstanceOf(EndTurn::class, $this->findMessage($messages, EndTurn::class));

        $finalized = $this->findMessage($messages, BattleFinalized::class);
        self::assertInstanceOf(BattleFinalized::class, $finalized);
        self::assertSame([$this->fireball->itemId->toString()], $finalized->keptConsumableIds);
        self::assertSame([$this->fireball->itemId->toString()], $finalized->selectedConsumableIds);

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertTrue($fight->additionalData['spellsKept'] ?? false);
        self::assertSame('magical_affinity', $fight->additionalData['heroAbility'] ?? null);
        self::assertSame(1, $fight->additionalData['itemDamage'] ?? null);
    }

    #[Test]
    public function otherHeroesConsumeTheFireball(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $tester = $this->createTester($this->createPlayer(null, [$this->fireball]));

        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([$this->fireball->itemId->toString()]),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result);
        self::assertFalse($completed->spellsKept);

        $removed = $this->findMessage($messages, RemoveItemFromInventory::class);
        self::assertInstanceOf(RemoveItemFromInventory::class, $removed);
        self::assertTrue($removed->itemId->equals($this->fireball->itemId));
        self::assertCount(1, array_filter($messages, static fn(object $message): bool => $message instanceof RemoveItemFromInventory));

        $finalized = $this->findMessage($messages, BattleFinalized::class);
        self::assertInstanceOf(BattleFinalized::class, $finalized);
        self::assertSame([], $finalized->keptConsumableIds);

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertFalse($fight->additionalData['spellsKept'] ?? true);
        self::assertNull($fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function nothingIsKeptWhenNoSpellWasUsed(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $tester = $this->createTester($this->createPlayer(HeroType::WIZARD, [$this->fireball]));

        [$battle] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize([]),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::DRAW, $completed->result);
        self::assertFalse($completed->spellsKept);
        self::assertNull($this->findMessage($messages, RemoveItemFromInventory::class));

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertFalse($fight->additionalData['spellsKept'] ?? true);
        self::assertNull($fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function aKeptSpellCanStillBeDroppedOnTheFieldLater(): void
    {
        $kept = Item::createMummy()->defeatMonster();
        $consumed = Item::createMummy()->defeatMonster();

        $this->field->onBattleFinalized(new BattleFinalized(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            finalUsedItems: [$kept, $consumed],
            finalTotalDamage: 7,
            selectedConsumableIds: [$kept->itemId->toString(), $consumed->itemId->toString()],
            keptConsumableIds: [$kept->itemId->toString()],
        ));

        $tester = MessageBusTester::create(
            static fn(GetPlayerPosition $_query): FieldPlace => FieldPlace::fromString('1,0'),
        );

        [, $messages] = $tester->handle($this->field->onItemRemovedFromInventory(...), new ItemRemovedFromInventory($this->gameId, $this->playerId, $kept));
        $placed = $this->findMessage($messages, ItemPlacedOnField::class);
        self::assertInstanceOf(ItemPlacedOnField::class, $placed, 'A kept fireball was never consumed: dropping it later puts it on the field');
        self::assertTrue($placed->itemId->equals($kept->itemId));

        [, $messages] = $tester->handle($this->field->onItemRemovedFromInventory(...), new ItemRemovedFromInventory($this->gameId, $this->playerId, $consumed));
        self::assertNull($this->findMessage($messages, ItemPlacedOnField::class), 'A consumed fireball is gone');
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
    private function createPlayer(?HeroType $hero, array $spells): object
    {
        return new class($hero, $spells) {
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
