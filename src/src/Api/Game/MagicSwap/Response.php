<?php

declare(strict_types=1);

namespace App\Api\Game\MagicSwap;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $targetPlayerId,
        public string $position,
        public bool $success = true,
    ) {}
}
