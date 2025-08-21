<?php

declare(strict_types=1);

namespace App\Game\Battle;

enum BattleResult: string
{
    case WIN = 'win';
    case LOOSE = 'loose';
    case DRAW = 'draw';
}
