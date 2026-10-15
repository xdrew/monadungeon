<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\LightningAttack;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $gameId,
        /** @var array<string, mixed>|null */
        public ?array $battleInfo,
        public bool $success = true,
    ) {}
}
