<?php

declare(strict_types=1);

namespace App\Game\Movement\Exception;

final class NotYourTurnException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('It is not your turn');
    }
}
