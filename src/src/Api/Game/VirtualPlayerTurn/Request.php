<?php

declare(strict_types=1);

namespace App\Api\Game\VirtualPlayerTurn;

final readonly class Request
{
    public function __construct(
        public string $gameId,
        public string $playerId,
        public ?string $strategy = null, // Optional: 'aggressive', 'defensive', 'balanced', 'treasure_hunter'
    ) {}
}