<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Karak: Regent — one hero of a duel locked in his strength (dispatched by the Battle fought in duel mode).
 *
 * @psalm-immutable
 */
final readonly class DuelSideCompleted implements Event
{
    /**
     * @param list<int> $diceResults
     * @param list<array<string, mixed>> $usedItems
     */
    public function __construct(
        public Uuid $duelId,
        public Uuid $gameId,
        public Uuid $battleId,
        public string $role,
        public Uuid $playerId,
        public int $total,
        public array $diceResults,
        public int $itemDamage,
        public array $usedItems,
        public bool $hpSacrificed = false,
        public bool $farseeing = false,
        public bool $rerolled = false,
        public int $onesRerolled = 0,
        public bool $tieIsWin = false,
        public bool $spellsKept = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [
            'dice' => $this->diceResults,
            'itemDamage' => $this->itemDamage,
            'usedItems' => $this->usedItems,
            'hpSacrificed' => $this->hpSacrificed,
            'farseeing' => $this->farseeing,
            'rerolled' => $this->rerolled,
            'onesRerolled' => $this->onesRerolled,
            'tieIsWin' => $this->tieIsWin,
            'spellsKept' => $this->spellsKept,
        ];
    }
}
