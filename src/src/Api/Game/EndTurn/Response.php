<?php

declare(strict_types=1);

namespace App\Api\Game\EndTurn;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public bool $success = true,
        public string $message = 'Turn ended successfully',
    ) {}
}
