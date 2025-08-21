<?php

declare(strict_types=1);

namespace App\Game\Turn\Error;

final class TurnAlreadyEndedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cannot perform action: turn has already ended');
    }
}
