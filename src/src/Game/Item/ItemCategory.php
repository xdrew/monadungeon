<?php

declare(strict_types=1);

namespace App\Game\Item;

enum ItemCategory: string
{
    case TREASURE = 'treasure';
    case WEAPON = 'weapon';
    case SPELL = 'spell';
    case KEY = 'key';

    public static function getRandom(): self
    {
        $cases = self::cases();

        return $cases[array_rand($cases)];
    }
}
