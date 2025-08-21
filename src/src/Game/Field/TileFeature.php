<?php

declare(strict_types=1);

namespace App\Game\Field;

enum TileFeature: string
{
    case HEALING_FOUNTAIN = 'healing_fountain';
    case TELEPORTATION_GATE = 'teleportation_gate';
}
