<?php

declare(strict_types=1);

namespace App\Api\Game\MovePlayer;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    /**
     * @param array<string, mixed>|null $battleInfo Battle information if a battle occurred
     * @param array<string, mixed>|null $itemInfo Item information if a pickable item is found
     */
    public function __construct(
        public Uuid $gameId,
        public ?array $battleInfo = null,
        public ?array $itemInfo = null,
    ) {}
}
