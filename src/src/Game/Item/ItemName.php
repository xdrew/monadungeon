<?php

declare(strict_types=1);

namespace App\Game\Item;

enum ItemName: string
{
    case DRAGON = 'dragon';
    case FALLEN = 'fallen';
    case SKELETON_KING = 'skeleton_king';
    case SKELETON_WARRIOR = 'skeleton_warrior';
    case GIANT_RAT = 'giant_rat';
    case GIANT_SPIDER = 'giant_spider';
    case MUMMY = 'mummy';
    case SKELETON_TURNKEY = 'skeleton_turnkey';
    case TREASURE_CHEST = 'treasure_chest';

    // Karak: Regent tokens
    case GIANT_BAT = 'giant_bat';
    case SKELETON_MAGE = 'skeleton_mage';

    /**
     * Synthetic target of a Battle Arena duel: guardHP carries the strength to beat.
     */
    case HERO = 'hero';

    /**
     * A random base-game token: never the dragon, never a Regent token, never the duel target.
     */
    public static function getRandom(): self
    {
        $base = [
            self::FALLEN,
            self::SKELETON_KING,
            self::SKELETON_WARRIOR,
            self::GIANT_RAT,
            self::GIANT_SPIDER,
            self::MUMMY,
            self::SKELETON_TURNKEY,
            self::TREASURE_CHEST,
        ];

        return $base[array_rand($base)];
    }

    public function getHp(): int
    {
        return match ($this) {
            self::DRAGON => 15,
            self::FALLEN => 12,
            self::SKELETON_KING => 10,
            self::SKELETON_WARRIOR => 9,
            self::GIANT_RAT => 5,
            self::GIANT_SPIDER => 6,
            self::MUMMY => 7,
            self::SKELETON_TURNKEY => 8,
            self::TREASURE_CHEST => 0,
            self::GIANT_BAT => 6,
            self::SKELETON_MAGE => 11,
            self::HERO => 0,
        };
    }
}
