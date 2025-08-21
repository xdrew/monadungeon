<?php

declare(strict_types=1);

namespace App\Api\Game\FinalizeBattle;

use App\Infrastructure\Uuid\Uuid;

final class Request
{
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        /**
         * @var array<string>
         */
        public array $selectedConsumableIds,
        public bool $pickupItem = false,
        public ?string $replaceItemId = null,
    ) {}
}
