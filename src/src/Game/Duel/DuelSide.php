<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;

/**
 * Karak: Regent — a Battle fought as one side of a Battle Arena duel.
 *
 * role: 'challenger' (the hero who entered the arena, rolls and decides first, nothing to beat yet)
 *    or 'opponent' (the summoned hero, rolls against the challenger's total in $toBeat).
 * auto: the Battle finalizes itself right after the roll (a virtual player, or a human summoned in a virtual player's turn).
 *
 * @psalm-immutable
 */
final readonly class DuelSide
{
    public const string CHALLENGER = 'challenger';
    public const string OPPONENT = 'opponent';

    public function __construct(
        public Uuid $duelId,
        public string $role,
        public ?int $toBeat,
        public bool $farseeing,
        public bool $auto,
        public Uuid $opponentId,
    ) {}

    public function isChallenger(): bool
    {
        return $this->role === self::CHALLENGER;
    }
}
