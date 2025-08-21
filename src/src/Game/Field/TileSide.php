<?php

declare(strict_types=1);

namespace App\Game\Field;

enum TileSide: int
{
    // Clockwise order to ease rotation
    case TOP = 0;
    case RIGHT = 1;
    case BOTTOM = 2;
    case LEFT = 3;

    public static function getRandomSide(): self
    {
        return self::cases()[array_rand(self::cases())];
    }

    public function getOppositeSide(): self
    {
        return match ($this) {
            self::TOP => self::BOTTOM,
            self::RIGHT => self::LEFT,
            self::BOTTOM => self::TOP,
            self::LEFT => self::RIGHT,
        };
    }
}
