<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\Tile;
use App\Game\Field\TileOrientation;
use App\Game\Player\Player;
use App\Infrastructure\Uuid\Uuid;

/**
 * Interface for virtual player decision-making strategies
 */
interface VirtualPlayerStrategy
{
    /**
     * Choose which tile to pick from available tiles
     * 
     * @param Tile[] $availableTiles
     */
    public function chooseTile(array $availableTiles, Field $field, Uuid $playerId): Tile;

    /**
     * Choose where to place the selected tile
     * 
     * @param FieldPlace[] $availablePlaces
     */
    public function chooseTilePlacement(Tile $tile, array $availablePlaces, Field $field, Uuid $playerId): FieldPlace;

    /**
     * Choose the optimal orientation for the tile at the chosen position
     */
    public function chooseTileOrientation(Tile $tile, FieldPlace $position, Field $field): TileOrientation;

    /**
     * Choose which position to move to
     * 
     * @param FieldPlace[] $availableMoves
     */
    public function chooseMovement(FieldPlace $currentPosition, array $availableMoves, Field $field, Player $player): FieldPlace;
}