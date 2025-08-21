<?php

declare(strict_types=1);

namespace App\Game\Movement\Exception;

final class CannotMoveAfterBattleException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cannot move after battle in the same turn');
    }
}
