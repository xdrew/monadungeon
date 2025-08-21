<?php

declare(strict_types=1);

namespace App\Api\Game\VirtualPlayerTurn;

final readonly class Response
{
    /**
     * @param array<array{type: string, details: array<string, mixed>, timestamp: int}> $actions
     */
    public function __construct(
        public string $gameId,
        public string $playerId,
        public array $actions,
        public bool $success,
    ) {}
}