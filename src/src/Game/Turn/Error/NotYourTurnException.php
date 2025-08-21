<?php

declare(strict_types=1);

namespace App\Game\Turn\Error;

final class NotYourTurnException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cannot perform action: it is not your turn');
    }
}
