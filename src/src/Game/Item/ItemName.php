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

    public static function getRandom(): self
    {
        $allCases = self::cases();
        $exceptDragon = array_filter($allCases, static fn(self $item) => $item !== self::DRAGON);

        return $exceptDragon[array_rand($exceptDragon)];
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
        };
    }
}
