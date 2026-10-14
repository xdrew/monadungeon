<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Bag\Bag;
use App\Game\Bag\GetBag;
use App\Game\Deck\DeckTile;
use App\Game\Field\ChooseFateweaverItem;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\Field\GetTile;
use App\Game\Field\Tile;
use App\Game\Field\TileOrientation;
use App\Game\Field\TilePlaced;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Item\ItemName;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnEnded;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Oracle "Fateweaver": a new room draws two tokens, the Oracle places one and returns the other to the bag.
 */
#[CoversClass(Field::class)]
final class OracleFateweaverTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    private Field $field;

    private Tile $room;

    private FieldPlace $place;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
        /** @var DeckTile $deckTile */
        $deckTile = DeckTile::create(orientation: TileOrientation::fourSide(), room: true);
        $this->room = Tile::fromDeckTile(Uuid::v7(), $deckTile);
        $this->place = FieldPlace::fromString('1,0');
    }

    #[Test]
    public function oracleDrawsTwoTokensAndWaitsForTheChoice(): void
    {
        $bag = $this->bag([Item::createTreasureChest(), Item::createGiantRat(), Item::createMummy()]);
        $tester = $this->createTester($bag, HeroType::ORACLE);

        $tester->handle($this->field->placeItem(...), $this->tilePlaced());

        $pending = $this->field->getPendingFateweaver();
        self::assertNotNull($pending);
        self::assertSame('1,0', $pending['fieldPlace']);
        self::assertSame([ItemName::TREASURE_CHEST, ItemName::GIANT_RAT], array_map(static fn(Item $item): ItemName => $item->name, $pending['items']));
        self::assertArrayNotHasKey('1,0', $this->field->getItems(), 'Nothing is placed before the choice');
        self::assertSame(1, $bag->count());

        $places = $this->field->getAvailablePlacesForPlayer(new GetAvailablePlacesForPlayer($this->gameId, $this->playerId, $tester->messageBus()));
        self::assertSame(['moveTo' => [], 'placeTile' => []], $places, 'Nothing can be done until the token is chosen');
    }

    #[Test]
    public function theChosenTokenIsPlacedAndTheOtherReturnsToTheBag(): void
    {
        $bag = $this->bag([Item::createTreasureChest(), Item::createGiantRat(), Item::createMummy()]);
        $tester = $this->createTester($bag, HeroType::ORACLE);
        $tester->handle($this->field->placeItem(...), $this->tilePlaced());
        $rat = $this->field->getPendingFateweaver()['items'][1] ?? null;
        self::assertInstanceOf(Item::class, $rat);

        [, $messages] = $tester->handle($this->field->chooseFateweaverItem(...), $this->choose($rat->itemId));

        self::assertNull($this->field->getPendingFateweaver());
        $placed = Item::fromAnything($this->field->getItems()['1,0']);
        self::assertSame(ItemName::GIANT_RAT, $placed->name);
        self::assertTrue($placed->itemId->equals($rat->itemId));
        self::assertSame(2, $bag->count(), 'The chest went back into the bag');

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::FATEWEAVER, $action->action);
        self::assertSame('1,0', $action->additionalData['fieldPlace'] ?? null);
        self::assertSame('giant_rat', $action->additionalData['chosen']['name'] ?? null);
        self::assertSame('treasure_chest', $action->additionalData['returned']['name'] ?? null);
        self::assertSame('fateweaver', $action->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function onlyOneOfTheDrawnTokensCanBeChosen(): void
    {
        $bag = $this->bag([Item::createTreasureChest(), Item::createGiantRat(), Item::createMummy()]);
        $tester = $this->createTester($bag, HeroType::ORACLE);
        $tester->handle($this->field->placeItem(...), $this->tilePlaced());

        try {
            $tester->handle($this->field->chooseFateweaverItem(...), $this->choose(Uuid::v7()));
            self::fail('A token that was not drawn cannot be chosen');
        } catch (\DomainException $e) {
            self::assertStringContainsString('not one of the drawn', $e->getMessage());
        }
        self::assertNotNull($this->field->getPendingFateweaver(), 'The choice is still pending');
        self::assertSame(1, $bag->count());
    }

    #[Test]
    public function choosingWithoutAPendingDrawIsRejected(): void
    {
        $bag = $this->bag([Item::createTreasureChest(), Item::createGiantRat()]);
        $tester = $this->createTester($bag, HeroType::ORACLE);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No Fateweaver choice is pending');
        $tester->handle($this->field->chooseFateweaverItem(...), $this->choose(Uuid::v7()));
    }

    #[Test]
    public function otherHeroesDrawASingleToken(): void
    {
        $bag = $this->bag([Item::createTreasureChest(), Item::createGiantRat(), Item::createMummy()]);
        $tester = $this->createTester($bag, HeroType::THIEF);

        $tester->handle($this->field->placeItem(...), $this->tilePlaced());

        self::assertNull($this->field->getPendingFateweaver());
        self::assertSame(ItemName::TREASURE_CHEST, Item::fromAnything($this->field->getItems()['1,0'])->name);
        self::assertSame(2, $bag->count());
    }

    #[Test]
    public function aVirtualOraclePrefersAChestAndThenTheWeakestMonster(): void
    {
        $bag = $this->bag([Item::createGiantRat(), Item::createTreasureChest(), Item::createMummy()]);
        $tester = $this->createTester($bag, HeroType::ORACLE, isAi: true);
        $tester->handle($this->field->placeItem(...), $this->tilePlaced());

        self::assertNull($this->field->getPendingFateweaver(), 'A virtual Oracle never waits');
        self::assertSame(ItemName::TREASURE_CHEST, Item::fromAnything($this->field->getItems()['1,0'])->name);
        self::assertSame(2, $bag->count());

        $field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
        $bag = $this->bag([Item::createMummy(), Item::createGiantRat()]);
        $tester = $this->createTester($bag, HeroType::ORACLE, isAi: true);
        $tester->handle($field->placeItem(...), $this->tilePlaced());

        self::assertSame(ItemName::GIANT_RAT, Item::fromAnything($field->getItems()['1,0'])->name, 'Rat (5) is weaker than the mummy (7)');
        self::assertSame(1, $bag->count());
    }

    #[Test]
    public function theLastTokenIsDrawnNormally(): void
    {
        $bag = $this->bag([Item::createGiantRat()]);
        $tester = $this->createTester($bag, HeroType::ORACLE);

        $tester->handle($this->field->placeItem(...), $this->tilePlaced());

        self::assertNull($this->field->getPendingFateweaver());
        self::assertSame(ItemName::GIANT_RAT, Item::fromAnything($this->field->getItems()['1,0'])->name);
        self::assertSame(0, $bag->count());
    }

    #[Test]
    public function anUnsettledChoiceIsResolvedWhenTheTurnEnds(): void
    {
        $bag = $this->bag([Item::createTreasureChest(), Item::createGiantRat(), Item::createMummy()]);
        $tester = $this->createTester($bag, HeroType::ORACLE);
        $tester->handle($this->field->placeItem(...), $this->tilePlaced());

        $tester->handle($this->field->onTurnEnded(...), new TurnEnded($this->turnId, $this->gameId, $this->playerId, new \DateTimeImmutable()));

        self::assertNull($this->field->getPendingFateweaver());
        self::assertSame(ItemName::TREASURE_CHEST, Item::fromAnything($this->field->getItems()['1,0'])->name, 'The first drawn token is placed');
        self::assertSame(2, $bag->count(), 'The rat went back into the bag');
    }

    /**
     * @param list<Item> $items
     */
    private function bag(array $items): Bag
    {
        return new Bag($this->gameId, $items);
    }

    private function tilePlaced(): TilePlaced
    {
        return new TilePlaced($this->gameId, $this->room->tileId, $this->place, $this->room->getOrientation());
    }

    private function choose(Uuid $itemId): ChooseFateweaverItem
    {
        return new ChooseFateweaverItem(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            itemId: $itemId,
        );
    }

    private function createTester(Bag $bag, ?HeroType $hero, bool $isAi = false): MessageBusTester
    {
        $player = new class ($hero, $isAi) {
            public function __construct(
                private readonly ?HeroType $hero,
                private readonly bool $isAi,
            ) {}

            public function getHero(): ?HeroType
            {
                return $this->hero;
            }

            public function isAi(): bool
            {
                return $this->isAi;
            }

            public function getHP(): int
            {
                return 5;
            }

            public function isDefeated(): bool
            {
                return false;
            }
        };
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
            static fn(GetBag $_query): Bag => $bag,
            fn(GetTile $_query): Tile => $this->room,
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            static fn(GetPlayer $_query): object => $player,
            static fn(GetGame $_query): object => $game,
            static fn(GetPlayerPosition $_query): FieldPlace => FieldPlace::fromString('0,0'),
        );
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
