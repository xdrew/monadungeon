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
        // The box: 80 tiles — the start (a 4-side fountain), 53 rooms, 20 corridors, 2 corner fountains and 4 straight
        // teleportation gates. Karak: Regent adds its 18 (regentTiles).
        $tiles = [
            ...DeckTile::create(orientation: TileOrientation::fourSide(), room: true, amount: 14),
            ...DeckTile::create(orientation: TileOrientation::threeSide(), room: true, amount: 15),
            ...DeckTile::create(orientation: TileOrientation::twoSideCorner(), room: true, amount: 13),
            ...DeckTile::create(orientation: TileOrientation::twoSideStraight(), room: true, amount: 11),
            ...DeckTile::create(orientation: TileOrientation::fourSide(), room: false, amount: 7),
            ...DeckTile::create(orientation: TileOrientation::threeSide(), room: false, amount: 5),
            ...DeckTile::create(orientation: TileOrientation::twoSideCorner(), room: false, amount: 4),
            ...DeckTile::create(orientation: TileOrientation::twoSideStraight(), room: false, amount: 4),
            ...DeckTile::create(
                orientation: TileOrientation::twoSideCorner(),
                room: false,
                amount: 2,
                features: [TileFeature::HEALING_FOUNTAIN],
            ),
            ...DeckTile::create(
                orientation: TileOrientation::twoSideStraight(),
                room: false,
                amount: 4,
                features: [TileFeature::TELEPORTATION_GATE],
            ),
        ];

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

            // In test mode, use ONLY the specified tiles, don't add more (scripted decks list Regent tiles by name)
            $deck = new self($command->gameId, $tiles);
            $messageContext->dispatch(new DeckCreated(gameId: $command->gameId, roomCount: $deck->roomCount, regent: $command->regent));

            return $deck;
        }
        // every tile of the box is in play (deckSize only shapes the console demos' scripted games)
        if ($command->deckSize !== \count($tiles)) {
            error_log('INFO deck.size_ignored ' . json_encode(['deckSize' => $command->deckSize, 'tiles' => \count($tiles)]));
        }
        shuffle($tiles);

        // Karak: Regent — the 18 expansion tiles are mixed into the deck
        if ($command->regent) {
            $tiles = [...$tiles, ...self::regentTiles()];
            shuffle($tiles);
        }

        // Add starting tile with healing fountain
        array_unshift($tiles, DeckTile::create(TileOrientation::fourSide(), false, 1, [TileFeature::HEALING_FOUNTAIN]));

        $deck = new self($command->gameId, $tiles);
        error_log('INFO deck.created ' . json_encode(['tiles' => \count($tiles), 'rooms' => $deck->roomCount, 'regent' => $command->regent]));
        $messageContext->dispatch(new DeckCreated(gameId: $command->gameId, roomCount: $deck->roomCount, regent: $command->regent));

        return $deck;
    }

    /**
     * Karak: Regent — 6 Battle Arenas, 4 Cursed Rooms (both Lord Karak rooms are corridors: no token is drawn) and 8 rooms.
     *
     * @return list<DeckTile>
     */
    private static function regentTiles(): array
    {
        return [
            ...DeckTile::create(TileOrientation::twoSideStraight(), false, 6, [TileFeature::BATTLE_ARENA]),
            ...DeckTile::create(TileOrientation::fourSide(), false, 4, [TileFeature::CURSED_ROOM]),
            ...DeckTile::create(TileOrientation::fourSide(), true, 2),
            ...DeckTile::create(TileOrientation::threeSide(), true, 2),
            ...DeckTile::create(TileOrientation::twoSideCorner(), true, 2),
            ...DeckTile::create(TileOrientation::twoSideStraight(), true, 2),
        ];
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
