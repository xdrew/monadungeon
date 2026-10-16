<?php

declare(strict_types=1);

namespace App\Api\Game\Duel\Prepare;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    /**
     * @param array<string, mixed>|null $duel The duel projection (state.duel)
     */
    public function __construct(
        public Uuid $gameId,
        public ?array $duel,
        public bool $success = true,
    ) {}
}
