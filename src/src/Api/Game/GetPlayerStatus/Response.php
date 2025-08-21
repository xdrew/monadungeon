<?php

declare(strict_types=1);

namespace App\Api\Game\GetPlayerStatus;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $playerId,
        public int $hp,
        public bool $isStunned,
        public array $inventory,
    ) {}
}
