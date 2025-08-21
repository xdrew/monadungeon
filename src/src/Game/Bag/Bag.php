<?php

declare(strict_types=1);

namespace App\Game\Bag;

use App\Game\Bag\Error\NoItemsLeftInBag;
use App\Game\Deck\DeckCreated;
use App\Game\Item\DoctrineDBAL\ItemArrayJsonType;
use App\Game\Item\Item;
use App\Game\Item\ItemType;
use App\Game\Testing\TestMode;
use App\Infrastructure\Doctrine\AggregateRoot;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Telephantast\MessageBus\EntityHandler\FindBy;
use Telephantast\MessageBus\EntityHandler\Property;
use Telephantast\MessageBus\Handler\Mapping\Handler;

#[Entity]
#[Table(schema: 'bag')]
#[FindBy(['gameId' => new Property('gameId')])]
class Bag extends AggregateRoot
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $gameId,
        /**
         * @var list<Item>
         */
        #[Column(type: ItemArrayJsonType::class, columnDefinition: 'jsonb')]
        private array $items,
    ) {}

    public static function createForTest(DeckCreated $event): self
    {
        $items = [];

        for ($i = 0; $i < 10; ++$i) {
            $items[] = Item::createDragon();
            $items[] = Item::createMummy();
            $items[] = Item::createMummy();
            $items[] = Item::createSkeletonWarrior();
            $items[] = Item::createSkeletonWarrior();
        }

        for ($i = 0; $i < 4; ++$i) {
            $items[] = Item::createGiantRat();
        }

        return new self($event->gameId, $items);
    }

    #[Handler]
    public static function createClassic(DeckCreated $event): self
    {
        //        return self::createForTest($event);
        $items = [];

        $testMode = TestMode::getInstance();
        $fixedBag = $testMode->getFixedBag($event->gameId->toString());

        if ($fixedBag !== null) {
            // Use predetermined item sequence for testing
            foreach ($fixedBag as $itemConfig) {
                if (\is_string($itemConfig)) {
                    // Simple item name
                    $items[] = Item::createFromName($itemConfig);
                } elseif (\is_array($itemConfig)) {
                    // Detailed item configuration
                    $name = 'Test Item';
                    if (isset($itemConfig['name']) && \is_string($itemConfig['name'])) {
                        $name = $itemConfig['name'];
                    }

                    $type = 'weapon';
                    if (isset($itemConfig['type']) && \is_string($itemConfig['type'])) {
                        $type = $itemConfig['type'];
                    }

                    $guardHP = 0;
                    if (isset($itemConfig['guardHP']) && \is_int($itemConfig['guardHP'])) {
                        $guardHP = $itemConfig['guardHP'];
                    }

                    $treasureValue = 0;
                    if (isset($itemConfig['treasureValue']) && \is_int($itemConfig['treasureValue'])) {
                        $treasureValue = $itemConfig['treasureValue'];
                    }

                    $endsGame = false;
                    if (isset($itemConfig['endsGame']) && \is_bool($itemConfig['endsGame'])) {
                        $endsGame = $itemConfig['endsGame'];
                    }

                    $items[] = Item::create(
                        $name,
                        ItemType::fromString($type),
                        $guardHP,
                        $treasureValue,
                        $endsGame,
                    );
                }
            }

            // In test mode, use ONLY the specified items, don't add more or shuffle
            return new self($event->gameId, $items);
        }
        // According to the rulebook, the bag contains:

        // 2 Fallen monsters
        for ($i = 0; $i < 2; ++$i) {
            $items[] = Item::createFallen();
        }

        // 3 skeleton kings
        for ($i = 0; $i < 3; ++$i) {
            $items[] = Item::createSkeletonKing();
        }

        // 5 skeleton warriors (assuming this is the count from the rulebook)
        for ($i = 0; $i < 5; ++$i) {
            $items[] = Item::createSkeletonWarrior();
        }

        // 8 giant rats
        for ($i = 0; $i < 8; ++$i) {
            $items[] = Item::createGiantRat();
        }

        // 4 giant spiders
        for ($i = 0; $i < 4; ++$i) {
            $items[] = Item::createGiantSpider();
        }

        // 8 mummies
        for ($i = 0; $i < 8; ++$i) {
            $items[] = Item::createMummy();
        }

        // 12 skeleton turnkeys (which drop keys for chests)
        for ($i = 0; $i < 12; ++$i) {
            $items[] = Item::createSkeletonTurnkey();
        }

        // 10 treasure chests (unlocked since they have no guard)
        for ($i = 0; $i < 10; ++$i) {
            $items[] = Item::createTreasureChest();
        }
        shuffle($items);

        $itemCount = \count($items);

        if ($event->roomCount - 1 > $itemCount) {
            for ($i = 0; $i < ($event->roomCount - 1 - $itemCount); ++$i) {
                $items[] = Item::createRandom();
            }
        } elseif ($event->roomCount - 1 < $itemCount) {
            $items = \array_slice($items, 0, $event->roomCount - 1);
        }
        // ensure dragon is always in the bag
        $items[] = Item::createDragon();

        shuffle($items);

        return new self($event->gameId, $items);
    }

    #[Handler]
    public function getInstance(GetBag $query): self
    {
        return $this;
    }

    /**
     * @throws NoItemsLeftInBag
     */
    public function getNextItem(): Item
    {
        if ($this->items === []) {
            throw new NoItemsLeftInBag();
        }

        return Item::fromAnything(array_shift($this->items));
    }

    /**
     * Set items for test mode - replaces the current items with a predetermined sequence.
     * @param array<int, string> $itemNames
     */
    public function setTestItems(array $itemNames): void
    {
        $this->items = [];
        foreach ($itemNames as $itemName) {
            $this->items[] = Item::createFromName($itemName);
        }
    }
}
