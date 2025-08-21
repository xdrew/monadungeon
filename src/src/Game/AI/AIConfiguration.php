<?php

declare(strict_types=1);

namespace App\Game\AI;

/**
 * Centralized AI configuration
 * All AI-related configuration should be defined here instead of YAML files
 */
final class AIConfiguration
{
    /**
     * Available AI strategies with their configuration
     */
    public const STRATEGIES = [
        'aggressive' => [
            'aggressive' => true,
            'preferTreasures' => true,
            'riskTolerance' => 0.9,
            'healingThreshold' => 1,
            'inventoryPriority' => ['axe', 'sword', 'fireball', 'dagger', 'key'],
            'description' => 'High risk, high reward - focuses on combat and treasure hunting',
        ],
        'defensive' => [
            'aggressive' => false,
            'preferTreasures' => false,
            'riskTolerance' => 0.3,
            'healingThreshold' => 3,
            'inventoryPriority' => ['key', 'fireball', 'sword', 'axe', 'dagger'],
            'description' => 'Cautious approach - prioritizes survival and healing',
        ],
        'treasure_hunter' => [
            'aggressive' => true,
            'preferTreasures' => true,
            'riskTolerance' => 0.8,
            'healingThreshold' => 2,
            'inventoryPriority' => ['key', 'axe', 'sword', 'fireball', 'dagger'],
            'description' => 'Focuses on collecting treasures and valuable items',
        ],
        'balanced' => [
            'aggressive' => true,
            'preferTreasures' => true,
            'riskTolerance' => 0.7,
            'healingThreshold' => 2,
            'inventoryPriority' => ['sword', 'axe', 'dagger', 'fireball', 'key'],
            'description' => 'Balanced approach between risk and reward',
        ],
        'speedrun' => [
            'aggressive' => true,
            'preferTreasures' => false,
            'riskTolerance' => 0.95,
            'healingThreshold' => 1,
            'inventoryPriority' => ['axe', 'sword', 'dagger'],
            'description' => 'Fastest possible completion, ignores non-essential items',
        ],
    ];
    
    /**
     * AI system configuration
     */
    public const SYSTEM_CONFIG = [
        'max_actions_per_turn' => 4,
        'action_delay_ms' => 100, // Delay between actions in milliseconds
        'enable_debug_logging' => false,
        'enable_action_history' => true,
        'max_turn_time_seconds' => 30,
    ];
    
    /**
     * Item value rankings for inventory management
     */
    public const ITEM_VALUES = [
        'axe' => 3,
        'sword' => 2,
        'dagger' => 1,
        'fireball' => 4,
        'key' => 5,
        'teleport_scroll' => 3,
        'healing_potion' => 4,
        'treasure' => 10,
    ];
    
    /**
     * Monster difficulty ratings
     */
    public const MONSTER_DIFFICULTY = [
        'skeleton' => 1,
        'skeleton_archer' => 2,
        'skeleton_turnkey' => 2,
        'skeleton_king' => 3,
        'orc' => 2,
        'orc_berserker' => 3,
        'dragon' => 5,
    ];
    
    /**
     * Get strategy configuration by name
     */
    public static function getStrategy(string $name): array
    {
        return self::STRATEGIES[$name] ?? self::STRATEGIES['balanced'];
    }
    
    /**
     * Get all available strategy names
     */
    public static function getAvailableStrategies(): array
    {
        return array_keys(self::STRATEGIES);
    }
    
    /**
     * Get item value for inventory decisions
     */
    public static function getItemValue(string $itemType): int
    {
        return self::ITEM_VALUES[$itemType] ?? 0;
    }
    
    /**
     * Get monster difficulty rating
     */
    public static function getMonsterDifficulty(string $monsterType): int
    {
        return self::MONSTER_DIFFICULTY[$monsterType] ?? 1;
    }
    
    /**
     * Check if a strategy exists
     */
    public static function isValidStrategy(string $name): bool
    {
        return isset(self::STRATEGIES[$name]);
    }
}