<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\Listen;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $gameId,
        public string $position,
        /** @var array<string, mixed>|null */
        public ?array $item,
        public bool $success = true,
    ) {}
}
