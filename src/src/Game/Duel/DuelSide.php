<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;

/**
 * Karak: Regent — a Battle fought as one side of a Battle Arena duel.
 *
 * role: 'challenger' (the hero who entered the arena, rolls and decides first, nothing to beat yet)
 *    or 'opponent' (the summoned hero, rolls against the challenger's total in $toBeat).
 * auto: nobody answers for this side (a virtual player, or a human away from the table).
 * preset: the spells (item ids) and the sacrifice chosen before the roll; the side is locked in with them right
 *    after the roll, unless a human may still reroll (Warrior "Double Attack").
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
        /** @var array{consumableIds: list<string>, sacrifice: bool}|null */
        public ?array $preset = null,
    ) {}

    public function isChallenger(): bool
    {
        return $this->role === self::CHALLENGER;
    }
}
