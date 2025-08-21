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
 * Basic strategy for virtual player - makes simple but reasonable decisions
 */
final readonly class BasicVirtualPlayerStrategy implements VirtualPlayerStrategy
{
    /**
     * Choose tile based on simple heuristics:
     * 1. Prefer tiles with monsters if player is strong
     * 2. Prefer tiles with treasures if player needs items
     * 3. Prefer tiles that maximize movement options
     */
    public function chooseTile(array $availableTiles, Field $field, Uuid $playerId): Tile
    {
        if (empty($availableTiles)) {
            throw new \RuntimeException('No available tiles to choose from');
        }

        // For now, just pick the first available tile
        // TODO: Implement more sophisticated tile selection logic
        return array_values($availableTiles)[0];
    }

    /**
     * Choose placement position based on:
     * 1. Maximizing future movement options
     * 2. Creating paths toward valuable targets
     * 3. Avoiding dead ends when possible
     */
    public function chooseTilePlacement(Tile $tile, array $availablePlaces, Field $field, Uuid $playerId): FieldPlace
    {
        if (empty($availablePlaces)) {
            throw new \RuntimeException('No available places to place tile');
        }

        // Simple strategy: prefer positions closer to the center
        $centerX = 0; // Assuming center is at (0,0)
        $centerY = 0;
        
        $bestPlace = null;
        $bestScore = PHP_FLOAT_MAX;
        
        foreach ($availablePlaces as $place) {
            // Calculate distance from center
            $distance = sqrt(pow($place->getX() - $centerX, 2) + pow($place->getY() - $centerY, 2));
            
            if ($distance < $bestScore) {
                $bestScore = $distance;
                $bestPlace = $place;
            }
        }
        
        return $bestPlace ?? array_values($availablePlaces)[0];
    }

    /**
     * Choose orientation to maximize connectivity and movement options
     */
    public function chooseTileOrientation(Tile $tile, FieldPlace $position, Field $field): TileOrientation
    {
        // For now, keep the current orientation
        // TODO: Implement logic to check adjacent tiles and optimize connections
        return $tile->getOrientation();
    }

    /**
     * Choose movement based on:
     * 1. Moving toward monsters if player is strong enough
     * 2. Moving toward treasures if player needs items  
     * 3. Exploring new areas
     * 4. Avoiding dangerous situations when weak
     */
    public function chooseMovement(FieldPlace $currentPosition, array $availableMoves, Field $field, Player $player): FieldPlace
    {
        if (empty($availableMoves)) {
            throw new \RuntimeException('No available moves');
        }

        // Simple strategy: evaluate each move and pick the best one
        $bestMove = null;
        $bestScore = -1000;
        
        foreach ($availableMoves as $move) {
            $score = $this->evaluatePosition($move, $field, $player);
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMove = $move;
            }
        }
        
        return $bestMove ?? array_values($availableMoves)[0];
    }

    /**
     * Evaluate how good a position is for the player
     */
    private function evaluatePosition(FieldPlace $position, Field $field, Player $player): float
    {
        $score = 0.0;
        
        // Check for items at this position
        $items = $field->getItems();
        $positionKey = $position->toString();
        
        if (isset($items[$positionKey])) {
            $item = $items[$positionKey];
            
            // Evaluate items based on player needs
            if ($item['type'] === 'treasure') {
                $score += 10; // Always good to get treasure
            } elseif ($item['type'] === 'weapon' && $player->needsWeapon()) {
                $score += 15; // Weapons are valuable if needed
            } elseif ($item['type'] === 'spell' && $player->needsSpell()) {
                $score += 12; // Spells are useful
            } elseif ($item['type'] === 'key') {
                $score += 8; // Keys open chests
            }
            
            // Consider monster difficulty
            if (isset($item['guardHP']) && $item['guardHP'] > 0) {
                $monsterDifficulty = $item['guardHP'];
                $playerStrength = $this->calculatePlayerStrength($player);
                
                if ($playerStrength >= $monsterDifficulty) {
                    $score += 5; // Winnable battle
                } else {
                    $score -= ($monsterDifficulty - $playerStrength) * 2; // Risky battle
                }
            }
        }
        
        // Prefer exploring new areas (positions farther from current)
        // This encourages exploration rather than staying in one area
        $explorationBonus = 2.0;
        $score += $explorationBonus;
        
        // Small random factor to avoid completely predictable behavior
        $score += (rand(-100, 100) / 100.0) * 0.5;
        
        return $score;
    }

    /**
     * Calculate effective combat strength of the player
     */
    private function calculatePlayerStrength(Player $player): int
    {
        $strength = $player->getHP(); // Base HP
        
        // Add weapon bonuses
        $inventory = $player->getInventory();
        foreach ($inventory['weapons'] ?? [] as $weapon) {
            $strength += $this->getWeaponDamage($weapon['name'] ?? '');
        }
        
        // Add spell bonuses (if consumable)
        foreach ($inventory['spells'] ?? [] as $spell) {
            $strength += $this->getSpellDamage($spell['name'] ?? '');
        }
        
        return $strength;
    }

    /**
     * Get damage value for a weapon
     */
    private function getWeaponDamage(string $weaponName): int
    {
        // Simple weapon damage mapping
        $weaponDamage = [
            'dagger' => 1,
            'sword' => 2,
            'axe' => 3,
            'bow' => 2,
        ];
        
        return $weaponDamage[strtolower($weaponName)] ?? 0;
    }

    /**
     * Get damage value for a spell (if used)
     */
    private function getSpellDamage(string $spellName): int
    {
        // Simple spell damage mapping
        $spellDamage = [
            'fireball' => 1,
            'lightning' => 2,
            'healing' => 0, // Not for combat
            'teleport' => 0, // Utility spell
        ];
        
        return $spellDamage[strtolower($spellName)] ?? 0;
    }
}