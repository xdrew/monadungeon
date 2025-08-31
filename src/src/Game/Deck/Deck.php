<?php

declare(strict_types=1);

namespace App\Game\Deck;

use App\Game\Deck\DoctrineDBAL\DeckTileArrayJsonType;
use App\Game\Deck\Error\NoTilesLeftInDeck;
use App\Game\Field\TileFeature;
use App\Game\Field\TileOrientation;
use App\Game\GameLifecycle\GameCreated;
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
use Telephantast\MessageBus\MessageContext;

#[Entity]
#[Table(schema: 'deck')]
#[FindBy(['gameId' => new Property('gameId')])]
class Deck extends AggregateRoot
{
    #[Column]
    private int $tilesTotalCount;

    #[Column]
    private int $roomCount;

    #[Column]
    private int $tilesRemainingCount;

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $gameId,
        /**
         * @var list<DeckTile>
         */
        #[Column(type: DeckTileArrayJsonType::class, columnDefinition: 'jsonb')]
        private array $tiles = [],
    ) {
        $this->tilesTotalCount = \count($this->tiles);
        $this->tilesRemainingCount = $this->tilesTotalCount;
        $this->roomCount = array_reduce($this->tiles, static fn(int $rooms, DeckTile $tile) => $rooms + ($tile->room ? 1 : 0), 0);
    }

    public static function createOnlyCrossroads(GameCreated $command, MessageContext $messageContext): self
    {
        $tilesFromCreate = DeckTile::create(
            orientation: TileOrientation::fourSide(),
            room: true,
            amount: max(1, $command->deckSize),
            features: [TileFeature::HEALING_FOUNTAIN],
        );
        $tiles = \is_array($tilesFromCreate) ? $tilesFromCreate : [$tilesFromCreate];
        $deck = new self($command->gameId, $tiles);
        $messageContext->dispatch(new DeckCreated(gameId: $command->gameId, roomCount: $deck->roomCount));

        return $deck;
    }

    #[Handler]
    public static function createClassic(GameCreated $command, MessageContext $messageContext): self
    {
//                return self::createOnlyCrossroads($command, $messageContext);
        // count: 1,     4 side heal start
        // count: 2,     2 side corner heal
        // count: 4,     2 side straight
        // count: 4,     2 side straight portal
        // count: 4,     2 side corner
        // count: 4,     4 side curse
        // count: 5,     3 side
        // count: 6,     2 side straight arena
        // count: 7,     4 side
        // count: 13,    2 side room straight
        // count: 15,    2 side room corner
        // count: 16,    4 side room
        // count: 17,    3 side room
        // total: 98

        // count: 1,     4 side heal start
        // count: 27,    4 side
        // count: 22,    3 side
        // count: 21,    2 side corner
        // count: 29,    2 side straight
        // total: 100
        $tiles = [
            ...DeckTile::create(
                orientation: TileOrientation::fourSide(),
                room: true,
                amount: 16,
            ),
            ...DeckTile::create(
                orientation: TileOrientation::fourSide(),
                room: false,
                amount: 11,
            ),
            ...DeckTile::create(
                orientation: TileOrientation::threeSide(),
                room: true,
                amount: 17,
            ),
            ...DeckTile::create(
                orientation: TileOrientation::threeSide(),
                room: false,
                amount: 5,
            ),
            ...DeckTile::create(
                orientation: TileOrientation::twoSideCorner(),
                room: true,
                amount: 15,
            ),
            ...DeckTile::create(
                orientation: TileOrientation::twoSideCorner(),
                room: false,
                amount: 4,
            ),
            ...DeckTile::create(
                orientation: TileOrientation::twoSideCorner(),
                room: false,
                amount: 2,
                features: [TileFeature::HEALING_FOUNTAIN],
            ),
            ...DeckTile::create(
                orientation: TileOrientation::twoSideStraight(),
                room: true,
                amount: 13,
            ),
            ...DeckTile::create(
                orientation: TileOrientation::twoSideStraight(),
                room: false,
                amount: 14,
            ),
            ...DeckTile::create(
                orientation: TileOrientation::twoSideStraight(),
                room: false,
                amount: 2,
                features: [TileFeature::TELEPORTATION_GATE],
            ),
        ];
        //        $tiles = [
        //            ...DeckTile::create(TileOrientation::fourSide(), true, random_int(125, 200)),
        //            ...DeckTile::create(TileOrientation::fourSide(), false, random_int(125, 200)),
        //            ...DeckTile::create(TileOrientation::threeSide(), true, random_int(125, 200)),
        //            ...DeckTile::create(TileOrientation::threeSide(), false, random_int(125, 200)),
        //            ...DeckTile::create(TileOrientation::twoSideCorner(), true, random_int(125, 200)),
        //            ...DeckTile::create(TileOrientation::twoSideCorner(), false, random_int(125, 200)),
        //            ...DeckTile::create(TileOrientation::twoSideStraight(), true, random_int(125, 200)),
        //            ...DeckTile::create(TileOrientation::twoSideStraight(), false, random_int(125, 200)),
        //        ];

        $testMode = TestMode::getInstance();
        $fixedDeck = $testMode->getFixedDeck($command->gameId->toString());

        if ($fixedDeck !== null) {
            // Use predetermined tile sequence for testing
            $tiles = [];
            foreach ($fixedDeck as $tileConfig) {
                if (\is_string($tileConfig)) {
                    // Simple tile name, create default tile
                    $tiles[] = DeckTile::createFromName($tileConfig);
                } elseif (\is_array($tileConfig)) {
                    // Detailed tile configuration
                    $features = [];
                    if (isset($tileConfig['features']) && \is_array($tileConfig['features'])) {
                        foreach ($tileConfig['features'] as $featureString) {
                            if (\is_string($featureString)) {
                                $features[] = TileFeature::from($featureString);
                            }
                        }
                    }
                    $orientationValue = $tileConfig['orientation'] ?? 'fourSide';
                    $orientation = \is_string($orientationValue) ? $orientationValue : 'fourSide';
                    $tiles[] = DeckTile::create(
                        TileOrientation::fromString($orientation),
                        (bool) ($tileConfig['room'] ?? false),
                        1,
                        $features,
                    );
                }
            }
            // Add starting tile with healing fountain
            array_unshift($tiles, DeckTile::create(TileOrientation::fourSide(), false, 1, [TileFeature::HEALING_FOUNTAIN]));

            // In test mode, use ONLY the specified tiles, don't add more
            $deck = new self($command->gameId, $tiles);
            $messageContext->dispatch(new DeckCreated(gameId: $command->gameId, roomCount: $deck->roomCount));

            return $deck;
        }
        shuffle($tiles);

        $missingTiles = $command->deckSize + 1 - \count($tiles);
        if ($missingTiles > 0) {
            $createdTiles = DeckTile::create(TileOrientation::random(), (bool) random_int(0, 1), $missingTiles);
            if ($createdTiles instanceof DeckTile) {
                $tiles[] = $createdTiles;
            } else {
                $tiles = array_merge($tiles, $createdTiles);
            }
        } else {
            $tiles = \array_slice($tiles, 0, \count($tiles) + $missingTiles - 1);
        }

        // Add starting tile with healing fountain
        array_unshift($tiles, DeckTile::create(TileOrientation::fourSide(), false, 1, [TileFeature::HEALING_FOUNTAIN]));

        $deck = new self($command->gameId, $tiles);
        $messageContext->dispatch(new DeckCreated(gameId: $command->gameId, roomCount: $deck->roomCount));

        return $deck;
    }

    #[Handler]
    public function getInstance(GetDeck $query): self
    {
        return $this;
    }

    public function getTilesTotalCount(): int
    {
        return $this->tilesTotalCount;
    }

    public function getRoomCount(): int
    {
        return $this->roomCount;
    }

    public function getTilesRemainingCount(): int
    {
        return $this->tilesRemainingCount;
    }

    /**
     * Checks if the deck is empty (no remaining tiles).
     */
    public function isEmpty(): bool
    {
        return $this->tilesRemainingCount <= 0 || $this->tiles === [];
    }

    /**
     * @throws NoTilesLeftInDeck
     */
    public function getNextTile(): DeckTile
    {
        if ($this->tiles === []) {
            throw new NoTilesLeftInDeck();
        }
        --$this->tilesRemainingCount;

        return array_shift($this->tiles);
    }

    /**
     * Set tiles for test mode - replaces the current tiles with a predetermined sequence.
     * @param string[] $tileNames
     */
    public function replaceTiles(array $tileNames): void
    {
        $this->tiles = [];
        foreach ($tileNames as $tileName) {
            $this->tiles[] = DeckTile::createFromName($tileName);
        }
        array_unshift($this->tiles, DeckTile::create(TileOrientation::fourSide(), false, 1, [TileFeature::HEALING_FOUNTAIN]));

        // Update counts based on new tiles
        $this->tilesTotalCount = \count($this->tiles);
        $this->tilesRemainingCount = $this->tilesTotalCount;
        $this->roomCount = array_reduce($this->tiles, static fn(int $rooms, DeckTile $tile) => $rooms + ($tile->room ? 1 : 0), 0);
    }
}
