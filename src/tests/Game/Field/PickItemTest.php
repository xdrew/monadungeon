<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\AddItemToInventory;
use App\Game\Player\Error\MissingKeyException;
use App\Game\Player\PickItem;
use App\Game\Player\QueryPlayerInventory;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\PerformTurnAction;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Picking up: only on the hero's own tile; a chest that never had a guard needs a key, the chest of a defeated
 * guard (the Fallen's treasure) does not.
 */
#[CoversClass(Field::class)]
final class PickItemTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    private Field $field;

    private string $heroAt = '1,0';

    /** @var list<Item> */
    private array $keys = [];

    /** @var list<object> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
    }

    #[Test]
    public function anItemOnAnotherTileCannotBePickedUp(): void
    {
        $this->setItems(['2,0' => Item::createGiantRat()->defeatMonster()]);

        try {
            $this->pick('2,0');
            self::fail('A remote pickup should be refused');
        } catch (\DomainException $e) {
            self::assertSame('You can only pick up items on your own tile', $e->getMessage());
        }
        self::assertArrayHasKey('2,0', $this->field->getItems(), 'The item stays where it lies');
        self::assertSame([], $this->seen);
    }

    #[Test]
    public function anItemOnTheHerosTileIsPickedUp(): void
    {
        $this->setItems(['1,0' => Item::createGiantRat()->defeatMonster()]);

        $this->pick('1,0');

        self::assertArrayNotHasKey('1,0', $this->field->getItems());
        self::assertNotEmpty(array_filter($this->seen, static fn(object $m): bool => $m instanceof AddItemToInventory));
    }

    #[Test]
    public function aChestThatNeverHadAGuardNeedsAndSpendsAKey(): void
    {
        $this->setItems(['1,0' => Item::createTreasureChest()]);

        try {
            $this->pick('1,0');
            self::fail('A locked chest needs a key');
        } catch (MissingKeyException) {
        }
        self::assertArrayHasKey('1,0', $this->field->getItems());

        $this->keys = [Item::createSkeletonTurnkey()->defeatMonster()];
        $this->pick('1,0');
        self::assertNotEmpty(array_filter($this->seen, static fn(object $m): bool => $m instanceof RemoveItemFromInventory), 'The key is spent');
        self::assertArrayNotHasKey('1,0', $this->field->getItems());
    }

    #[Test]
    public function theChestOfADefeatedGuardIsPickedUpWithoutAKey(): void
    {
        $this->setItems(['1,0' => Item::createFallen()->defeatMonster()]);

        $this->pick('1,0');

        self::assertArrayNotHasKey('1,0', $this->field->getItems());
        self::assertSame([], array_values(array_filter($this->seen, static fn(object $m): bool => $m instanceof RemoveItemFromInventory)), 'No key spent');
    }

    private function pick(string $position): void
    {
        $this->tester()->handle($this->field->pickItemFromField(...), new PickItem(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            position: FieldPlace::fromString($position),
        ));
    }

    /**
     * @param array<string, Item> $items
     */
    private function setItems(array $items): void
    {
        (new \ReflectionProperty(Field::class, 'items'))->setValue($this->field, $items);
    }

    private function tester(): MessageBusTester
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
            static fn(GetGame $_query): object => $game,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetPlayerPosition $_query): FieldPlace => FieldPlace::fromString($this->heroAt),
            fn(QueryPlayerInventory $_query): array => ['key' => $this->keys, 'weapon' => [], 'spell' => [], 'treasure' => []],
            function (AddItemToInventory $command): void { $this->seen[] = $command; },
            function (RemoveItemFromInventory $command): void { $this->seen[] = $command; },
            static fn(PerformTurnAction $_command) => null,
        );
    }
}
