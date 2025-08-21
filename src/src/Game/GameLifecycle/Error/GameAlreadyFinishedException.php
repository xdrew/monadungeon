<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle\Error;

final class GameAlreadyFinishedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cannot perform action: game has already finished');
    }
}