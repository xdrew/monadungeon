<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Game\Deck\DeckTile;
use App\Game\Deck\Error\NoTilesLeftInDeck;
use App\Game\Deck\GetDeck;
use App\Game\Field\DoctrineDBAL\TileFeatureArrayType;
use App\Game\Field\DoctrineDBAL\TileOrientationType;
use App\Game\Field\Error\CannotFindDeck;
use App\Game\Field\Error\CannotPlaceTileUntillPreviousTileIsNotPlaced;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\Turn\Error\NotYourTurnException;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Infrastructure\Doctrine\AggregateRoot;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidArrayJsonType;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Telephantast\MessageBus\EntityHandler\FindBy;
use Telephantast\MessageBus\EntityHandler\Property;
use Telephantast\MessageBus\Handler\Mapping\Handler;
use Telephantast\MessageBus\MessageContext;

#[Entity]
#[Table(schema: 'field')]
#[FindBy(['tileId' => new Property('tileId')])]
class Tile extends AggregateRoot
{
    /**
     * @var list<Uuid>
     */
    #[Column(type: UuidArrayJsonType::class)]
    private array $players = [];

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        public readonly Uuid $tileId,
        #[Column(type: TileOrientationType::class)]
        private TileOrientation $orientation,
        #[Column(type: BooleanType::class)]
        public readonly bool $room,
        /**
         * @var array<TileFeature>
         */
        #[Column(type: TileFeatureArrayType::class, nullable: false, options: ['default' => '[]'])]
        private array $features = [],
    ) {}

    public static function fromDeckTile(Uuid $tileId, DeckTile $deckTile): self
    {
        return new self(
            tileId: $tileId,
            orientation: $deckTile->orientation,
            room: $deckTile->room,
            features: $deckTile->features,
        );
    }

    /**
     * @throws CannotPlaceTileUntillPreviousTileIsNotPlaced
     * @throws CannotFindDeck
     * @throws NoTilesLeftInDeck
     * @throws NotYourTurnException
     */
    #[Handler]
    public static function pickFromDeck(PickTile $command, MessageContext $messageContext): self
    {
        // Check if it's the player's turn
        $currentPlayerId = $messageContext->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$command->playerId->equals($currentPlayerId)) {
            throw new NotYourTurnException();
        }

        $field = $messageContext->dispatch(new GetField($command->gameId));
        $placedTiles = $field->getPlacedTilesAmount();
        $deck = $messageContext->dispatch(new GetDeck($command->gameId));
        $deckTotalTiles = $deck->getTilesTotalCount();
        $deckRemainingTiles = $deck->getTilesRemainingCount();

        if ($placedTiles !== $deckTotalTiles - $deckRemainingTiles) {
            throw new CannotPlaceTileUntillPreviousTileIsNotPlaced();
        }

        try {
            $deckTile = $deck->getNextTile();
        } catch (NoTilesLeftInDeck $e) {
            throw $e;
        }

        $tile = self::fromDeckTile($command->tileId, $deckTile);

        // Try all possible rotations to find one that keeps the required side open
        $validRotation = false;
        $currentOrientation = clone $tile->orientation;

        // Try rotations clockwise starting from TOP
        for ($i = 0; $i < 4; ++$i) {
            $rotationValue = (0 - $i + 4) % 4; // Start from TOP (0) and go clockwise
            $rotationSide = TileSide::from($rotationValue);

            $testOrientation = $currentOrientation->getOrientationForTopSide($rotationSide);
            if ($testOrientation->isOpenedSide($command->requiredOpenSide)) {
                $tile->orientation = $testOrientation;
                $validRotation = true;
                break;
            }
        }

        // If no valid rotation found, keep the original orientation
        if (!$validRotation) {
            $tile->orientation = $currentOrientation;
        }

        $messageContext->dispatch(new TilePicked(
            tileId: $command->tileId,
            orientation: $tile->getOrientation(),
            room: $tile->room,
        ));

        // Record this action for the turn
        $messageContext->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: TurnAction::PICK_TILE,
            tileId: $command->tileId,
        ));

        return $tile;
    }

    #[Handler]
    public function getInstance(GetTile $query): self
    {
        return $this;
    }

    /**
     * @throws NotYourTurnException
     */
    #[Handler]
    public function rotate(RotateTile $command, MessageContext $messageContext): void
    {
        // Check if it's the player's turn
        $currentPlayerId = $messageContext->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$command->playerId->equals($currentPlayerId)) {
            throw new NotYourTurnException();
        }

        // Get the current orientation
        $currentOrientation = clone $this->orientation;

        // Try all possible rotations to find one that keeps the required side open
        $validRotation = false;

        // Start with the provided top side and try all rotations clockwise
        // To go clockwise from any position, we subtract from the start position
        // Since TileSide values are 0-3, subtracting and using modulo 4 gives us clockwise order
        for ($i = 0; $i < 4; ++$i) {
            $rotationValue = ($command->topSide->value - $i + 4) % 4;
            $rotationSide = TileSide::from($rotationValue);

            $testOrientation = $currentOrientation->getOrientationForTopSide($rotationSide);
            if ($testOrientation->isOpenedSide($command->requiredOpenSide)) {
                $this->orientation = $testOrientation;
                $validRotation = true;
                break;
            }
        }

        if (!$validRotation) {
            // If no valid rotation is found, use the requested rotation anyway
            $this->orientation = $currentOrientation->getOrientationForTopSide($command->topSide);
        }

        // Record this action for the turn
        $messageContext->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: TurnAction::ROTATE_TILE,
            tileId: $this->tileId,
            additionalData: ['side' => $command->topSide->value],
        ));
    }

    public function getOrientation(): TileOrientation
    {
        return $this->orientation;
    }

    public function hasOpenedSideBySiblingSide(TileSide $siblingTileSide): bool
    {
        return $this->orientation->isOpenedSide($siblingTileSide->getOppositeSide());
    }

    /**
     * Checks if the specified side of the tile is open.
     */
    public function hasOpenedSide(TileSide $side): bool
    {
        return $this->orientation->isOpenedSide($side);
    }

    /**
     * Rotates the tile to find an orientation that has an opening toward the player.
     * The field requirements from other adjacent tiles are NOT considered.
     *
     * @param TileOrientation $requiredOrientation The field requirements (for compatibility)
     * @param TileSide $requiredOpenSide The side that must be open (facing player)
     * @return bool True if an orientation with the required opening was found
     */
    public function rotateToMatchBothConditions(TileOrientation $requiredOrientation, TileSide $requiredOpenSide): bool
    {
        // Store the original orientation for reference
        $originalOrientation = clone $this->orientation;

        // First check if the current orientation already has an opening toward the player
        if ($this->orientation->isOpenedSide($requiredOpenSide)) {
            // Already has the required opening, no need to rotate
            return true;
        }

        // Try all possible rotations (0°, 90°, 180°, 270°)
        $possibleOrientations = [];

        for ($i = 0; $i <= 3; ++$i) {
            $rotationSide = match ($i) {
                0 => TileSide::TOP,    // Original orientation (0°)
                1 => TileSide::RIGHT,  // Rotate 90° counterclockwise
                2 => TileSide::BOTTOM, // Rotate 180°
                3 => TileSide::LEFT,   // Rotate 270° counterclockwise
                default => TileSide::TOP
            };

            // Get the orientation after rotation
            $rotatedOrientation = $originalOrientation->getOrientationForTopSide($rotationSide);

            // Check if this rotation has an opening toward the player
            if ($rotatedOrientation->isOpenedSide($requiredOpenSide)) {
                $possibleOrientations[] = [
                    'rotation' => $i,
                    'orientation' => $rotatedOrientation,
                    'matchesField' => $rotatedOrientation->hasCommonOpenedSides($requiredOrientation),
                ];
            }
        }

        // If we found orientations with the required opening
        if ($possibleOrientations !== []) {
            // First try to find one that also matches the field requirements
            foreach ($possibleOrientations as $option) {
                if ($option['matchesField']) {
                    $this->orientation = $option['orientation'];

                    return true;
                }
            }

            // If no orientation matches both, just use the first one that has the required opening
            $this->orientation = $possibleOrientations[0]['orientation'];

            return true;
        }

        // No orientation found that has an opening toward the player
        return false;
    }

    /**
     * @return array<TileFeature>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    public function hasFeature(TileFeature $feature): bool
    {
        return \in_array($feature, $this->features, true);
    }
}
