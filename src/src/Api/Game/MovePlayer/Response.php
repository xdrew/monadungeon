<?php

declare(strict_types=1);

namespace App\Api\Game\MovePlayer;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    /**
     * @param array<string, mixed>|null $battleInfo Battle information if a battle occurred
     * @param array<string, mixed>|null $itemInfo Item information if a pickable item is found
     * @param array{position: string, playerId: string, candidates: list<string>}|null $arenaChoice Karak: Regent — a Battle Arena rival to choose
     */
    public function __construct(
        public Uuid $gameId,
        public ?array $battleInfo = null,
        public ?array $itemInfo = null,
        public ?array $arenaChoice = null,
    ) {}
}
