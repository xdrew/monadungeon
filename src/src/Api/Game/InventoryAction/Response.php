<?php

declare(strict_types=1);

namespace App\Api\Game\InventoryAction;

final readonly class Response
{
    public function __construct(
        public string $gameId,
        public string $playerId,
        public string $action,
    ) {}
}
