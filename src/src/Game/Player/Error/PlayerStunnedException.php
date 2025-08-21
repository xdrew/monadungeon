<?php

declare(strict_types=1);

namespace App\Game\Player\Error;

final class PlayerStunnedException extends \RuntimeException
{
    public function __construct(string $message = 'Player is stunned and cannot perform actions')
    {
        parent::__construct($message);
    }
}
