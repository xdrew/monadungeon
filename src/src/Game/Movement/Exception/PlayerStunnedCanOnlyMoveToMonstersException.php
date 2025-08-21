<?php

declare(strict_types=1);

namespace App\Game\Movement\Exception;

final class PlayerStunnedCanOnlyMoveToMonstersException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Stunned players can only move to tiles with monsters');
    }
}
