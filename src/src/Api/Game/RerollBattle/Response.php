<?php

declare(strict_types=1);

namespace App\Api\Game\RerollBattle;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    /**
     * @param array<string, mixed>|null $battleInfo Updated battle information after the reroll
     */
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public ?array $battleInfo = null,
    ) {}
}
