<?php

declare(strict_types=1);

namespace App\Api\Game\FinalizeBattle;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public Uuid $playerId,
        public int $finalTotalDamage,
        public bool $success = true,
        public bool $itemPickedUp = false,
    ) {}
}
