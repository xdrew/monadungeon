<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\Retreat;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public string $position,
        public bool $success = true,
    ) {}
}
