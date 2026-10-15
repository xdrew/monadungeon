<?php

declare(strict_types=1);

namespace App\Game\Duel;

/**
 * Karak: Regent — the steps of a Battle Arena duel, in order.
 */
enum DuelPhase: string
{
    case CHALLENGER_ROLL = 'challenger_roll';
    case OPPONENT_ROLL = 'opponent_roll';
    case LOOT = 'loot';
    case DONE = 'done';

    public function rank(): int
    {
        return match ($this) {
            self::CHALLENGER_ROLL => 0,
            self::OPPONENT_ROLL => 1,
            self::LOOT => 2,
            self::DONE => 3,
        };
    }

    public function isSettled(): bool
    {
        return $this === self::DONE;
    }
}
