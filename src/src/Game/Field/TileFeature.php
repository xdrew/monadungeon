<?php

declare(strict_types=1);

namespace App\Game\Field;

enum TileFeature: string
{
    case HEALING_FOUNTAIN = 'healing_fountain';
    case TELEPORTATION_GATE = 'teleportation_gate';

    // Karak: Regent — Lord Karak rooms
    case BATTLE_ARENA = 'battle_arena';
    case CURSED_ROOM = 'cursed_room';
}
