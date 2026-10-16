<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Infrastructure\Uuid\Uuid;

/**
 * Plays one whole turn for a seat and tells what it did (the actions the browsers play back).
 */
interface AiTurnTaker
{
    /**
     * @return list<array<string, mixed>>
     */
    public function executeTurn(Uuid $gameId, Uuid $playerId, string $strategyType = 'balanced'): array;
}
