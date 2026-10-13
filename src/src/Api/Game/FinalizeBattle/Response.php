<?php

declare(strict_types=1);

namespace App\Api\Game\FinalizeBattle;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public Uuid $playerId,
        public int $finalTotalDamage,
        public bool $success = true,
        public bool $itemPickedUp = false,
        /**
         * Warrior "Reincarnation": the hero lost the last HP and must choose a healing fountain before the turn ends.
         */
        public bool $awaitingReincarnation = false,
        /**
         * @var list<string> Healing fountain positions ("x,y") available for reincarnation
         */
        public array $healingFountainPositions = [],
    ) {}
}
