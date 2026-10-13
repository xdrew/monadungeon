<?php

declare(strict_types=1);

namespace App\Api\Game\PickHero;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public string $hero,
    ) {}
}
