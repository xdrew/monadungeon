<?php

declare(strict_types=1);

namespace App\Api\Auth\MonadLogin;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $playerId,
        public string $walletAddress,
        public ?string $username = null,
    ) {}
}