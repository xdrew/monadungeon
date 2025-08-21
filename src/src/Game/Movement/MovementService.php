<?php

declare(strict_types=1);

namespace App\Game\Movement;

use App\Game\Field\FieldPlace;
use App\Game\Field\Tile;
use App\Game\Field\TileFeature;
use App\Game\Field\TileSide;
use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;

final class MovementService
{
    /**
     * @param array<string, list<string>> $transitions
     * @param array<string, Uuid|string> $placedTiles
     * @param array<string, Tile> $tilesCache
     * @param array<string, FieldPlace> $playerPositions
     * @param array<string, list<FieldPlace>> $teleportationConnections
     * @param array<string, \App\Game\Item\Item|array<string, mixed>> $items
     */
    public function __construct(
        private array $transitions,
        private array $placedTiles,
        private readonly array $tilesCache,
        private array $playerPositions,
        private array $teleportationConnections,
        private array $items = [],
    ) {}

    /**
     * Check if a player can move from one position to another.
     */
    public function canTransition(FieldPlace $from, FieldPlace $to): bool
    {
        if ($from->equals($to)) {
            return false;
        }

        $fromKey = $from->toString();
        $toKey = $to->toString();

        return isset($this->transitions[$fromKey]) && \in_array($toKey, $this->transitions[$fromKey], true);
    }

    /**
     * Get all possible destinations from a given position.
     *
     * @return list<FieldPlace>
     */
    public function getTransitionsFrom(FieldPlace $from): array
    {
        $fromKey = $from->toString();
        $transitions = $this->transitions[$fromKey] ?? [];

        return array_map(
            static fn(string $positionKey) => FieldPlace::fromString($positionKey),
            $transitions,
        );
    }

    /**
     * Get all positions that can reach a given destination.
     *
     * @return list<FieldPlace>
     */
    public function getTransitionsTo(FieldPlace $to): array
    {
        $toKey = $to->toString();
        $sources = [];

        foreach ($this->transitions as $fromKey => $destinations) {
            if (\in_array($toKey, $destinations, true)) {
                $sources[] = FieldPlace::fromString($fromKey);
            }
        }

        return $sources;
    }

    /**
     * Get all possible destinations for a player, including movement validation.
     *
     * @return list<FieldPlace>
     */
    public function getPossibleDestinations(Uuid $playerId, int $playerHp, ?Uuid $defeatedMonster = null): array
    {
        $playerPosition = $this->getPlayerPosition($playerId);
        if (!$playerPosition) {
            return [];
        }

        $allDestinations = $this->getTransitionsFrom($playerPosition);
        $validDestinations = [];

        foreach ($allDestinations as $destination) {
            // Check if there's a tile at the destination
            $destinationKey = $destination->toString();
            if (!isset($this->placedTiles[$destinationKey])) {
                continue;
            }

            // If player is stunned (0 HP), they can only move to tiles with monsters
            if ($playerHp === 0) {
                // Check if destination has an undefeated monster (item with guardHP > 0)
                $hasMonster = false;
                if (isset($this->items[$destinationKey])) {
                    $item = Item::fromAnything($this->items[$destinationKey]);
                    // Check if item has guardHP property and it's greater than 0
                    if ($item->guardHP > 0 && !$item->guardDefeated) {
                        $hasMonster = true;
                    }
                }

                if ($hasMonster) {
                    $validDestinations[] = $destination;
                }

                continue;
            }

            // Normal movement - exclude tiles with undefeated monsters
            $hasUndefeatedMonster = false;

            // Check if destination has an undefeated monster
            if (isset($this->items[$destinationKey])) {
                $item = Item::fromAnything($this->items[$destinationKey]);
                // Check if item has guardHP property and it's greater than 0
                if ($item->guardHP > 0 && !$item->guardDefeated) {
                    // Check if this monster was defeated (defeatedMonster contains the tileId where monster was defeated)
                    $tileId = $this->placedTiles[$destinationKey];
                    if (\is_string($tileId)) {
                        $tileId = Uuid::fromString($tileId);
                    }
                    if ($defeatedMonster === null || !$defeatedMonster->equals($tileId)) {
                        $hasUndefeatedMonster = true;
                    }
                }
            }

            if (!$hasUndefeatedMonster) {
                $validDestinations[] = $destination;
            }
        }

        return $validDestinations;
    }

    /**
     * Update transitions when a new tile is placed.
     *
     * @param array<string, Uuid|string> $placedTiles
     * @param array<string, Tile> $tilesCache
     * @return array<string, list<string>>
     */
    public function updateTransitionsForTile(
        FieldPlace $position,
        Tile $tile,
        array $placedTiles,
        array $tilesCache,
    ): array {
        $positionKey = $position->toString();
        if (!isset($this->transitions[$positionKey])) {
            $this->transitions[$positionKey] = [];
        }

        // Check all 4 sides of the tile
        foreach ($position->getAllSiblingsBySides() as $side => $siblingPosition) {
            $siblingKey = $siblingPosition->toString();

            // Skip if no tile at sibling position
            if (!isset($placedTiles[$siblingKey])) {
                continue;
            }

            $tileSide = TileSide::from($side);
            $oppositeSide = $tileSide->getOppositeSide();

            // Check if current tile has opening on this side
            if (!$tile->hasOpenedSide($tileSide)) {
                continue;
            }

            // Get sibling tile and check if it has opening on opposite side
            $siblingTileId = $placedTiles[$siblingKey];
            if (\is_string($siblingTileId)) {
                $siblingTileId = Uuid::fromString($siblingTileId);
            }
            $siblingTile = $tilesCache[$siblingTileId->toString()] ?? null;

            if (!$siblingTile || !$siblingTile->hasOpenedSide($oppositeSide)) {
                continue;
            }

            // Add bidirectional transitions
            if (!\in_array($siblingKey, $this->transitions[$positionKey], true)) {
                $this->transitions[$positionKey][] = $siblingKey;
            }

            if (!isset($this->transitions[$siblingKey])) {
                $this->transitions[$siblingKey] = [];
            }
            if (!\in_array($positionKey, $this->transitions[$siblingKey], true)) {
                $this->transitions[$siblingKey][] = $positionKey;
            }
        }

        // Handle teleportation gates
        if ($tile->hasFeature(TileFeature::TELEPORTATION_GATE)) {
            $this->updateTeleportationConnections($position, $placedTiles, $tilesCache);
        }

        return $this->transitions;
    }

    /**
     * Get all transitions (for debugging).
     *
     * @return array<string, list<string>>
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    /**
     * Get teleportation connections.
     *
     * @return array<string, list<FieldPlace>>
     */
    public function getTeleportationConnections(): array
    {
        return $this->teleportationConnections;
    }

    /**
     * Update teleportation connections between gates.
     *
     * @param array<string, Uuid|string> $placedTiles
     * @param array<string, Tile> $tilesCache
     */
    private function updateTeleportationConnections(
        FieldPlace $newGatePosition,
        array $placedTiles,
        array $tilesCache,
    ): void {
        /** @var list<string> $teleportationGates */
        $teleportationGates = [];

        // Find all teleportation gates
        foreach ($placedTiles as $positionKey => $tileId) {
            if (\is_string($tileId)) {
                $tileId = Uuid::fromString($tileId);
            }
            $tile = $tilesCache[$tileId->toString()] ?? null;
            if ($tile && $tile->hasFeature(TileFeature::TELEPORTATION_GATE)) {
                $teleportationGates[] = $positionKey;
            }
        }

        // Connect all gates to each other (including the new one)
        foreach ($teleportationGates as $gateKey1) {
            if (!isset($this->transitions[$gateKey1])) {
                $this->transitions[$gateKey1] = [];
            }

            foreach ($teleportationGates as $gateKey2) {
                if ($gateKey1 !== $gateKey2 && !\in_array($gateKey2, $this->transitions[$gateKey1], true)) {
                    $this->transitions[$gateKey1][] = $gateKey2;
                }
            }
        }

        // Update teleportation connections cache
        $this->teleportationConnections = [];
        foreach ($teleportationGates as $gateKey) {
            /** @var list<string> $filteredGates */
            $filteredGates = array_filter($teleportationGates, static fn(string $key): bool => $key !== $gateKey);
            $this->teleportationConnections[$gateKey] = array_map(
                static fn(string $key): FieldPlace => FieldPlace::fromString($key),
                $filteredGates,
            );
        }
    }

    /**
     * Get player's current position.
     */
    private function getPlayerPosition(Uuid $playerId): ?FieldPlace
    {
        return $this->playerPositions[$playerId->toString()] ?? null;
    }
}
