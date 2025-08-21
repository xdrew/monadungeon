<?php

declare(strict_types=1);

namespace App\Api\Game\GetCurrentPlayer;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $playerId,
    ) {}
}
