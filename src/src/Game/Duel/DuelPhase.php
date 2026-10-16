<?php

declare(strict_types=1);

namespace App\Game\Duel;

/**
 * Karak: Regent — the steps of a Battle Arena duel, in order: both heroes choose their spells before any
 * die is rolled (the challenger first, then the summoned hero), then they roll in the same order.
 */
enum DuelPhase: string
{
    case CHALLENGER_PREPARE = 'challenger_prepare';
    case OPPONENT_PREPARE = 'opponent_prepare';
    case CHALLENGER_ROLL = 'challenger_roll';
    case OPPONENT_ROLL = 'opponent_roll';
    case LOOT = 'loot';
    case DONE = 'done';

    public function rank(): int
    {
        return match ($this) {
            self::CHALLENGER_PREPARE => 0,
            self::OPPONENT_PREPARE => 1,
            self::CHALLENGER_ROLL => 2,
            self::OPPONENT_ROLL => 3,
            self::LOOT => 4,
            self::DONE => 5,
        };
    }

    public function isSettled(): bool
    {
        return $this === self::DONE;
    }
}
