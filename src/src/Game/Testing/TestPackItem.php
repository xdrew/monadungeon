<?php

declare(strict_types=1);

namespace App\Game\Testing;

use App\Game\Item\Item;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;

/**
 * An item for a player's pack preset by the test mode: the loot of its usual guard, already won.
 */
final class TestPackItem
{
    /**
     * The guard each pack item comes from in the bag.
     */
    private const array GUARD = [
        'key' => ItemName::SKELETON_TURNKEY,
        'dagger' => ItemName::GIANT_RAT,
        'sword' => ItemName::SKELETON_WARRIOR,
        'axe' => ItemName::SKELETON_KING,
        'fireball' => ItemName::MUMMY,
        'teleport' => ItemName::GIANT_SPIDER,
        'frost_fist' => ItemName::SKELETON_MAGE,
        'dark_thorn' => ItemName::GIANT_BAT,
        'chest' => ItemName::TREASURE_CHEST,
        'ruby_chest' => ItemName::TREASURE_CHEST,
    ];

    /**
     * @param string|array{type: string, treasureValue?: int} $spec an item type ("sword") or {type, treasureValue?}
     *
     * @throws \InvalidArgumentException for an unknown item type
     */
    public static function create(string|array $spec): Item
    {
        $type = \is_string($spec) ? $spec : $spec['type'];
        $guard = self::GUARD[$type] ?? null;
        $itemType = ItemType::tryFrom($type);
        if ($guard === null || $itemType === null) {
            throw new \InvalidArgumentException("Unknown pack item type for the test mode: {$type}");
        }

        return new Item(
            name: $guard,
            type: $itemType,
            treasureValue: \is_array($spec) ? ($spec['treasureValue'] ?? null) : null,
            guardDefeated: true,
        );
    }
}
