<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Karak: Regent — a hero of a duel chooses, before any die is rolled, the damage spells he spends and whether
 * he sacrifices 1 HP (Warlock): the challenger first, then the summoned hero.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class PrepareDuelSide implements Message
{
    /**
     * @param list<string> $consumableIds
     */
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public Uuid $duelId,
        public array $consumableIds = [],
        public bool $sacrifice = false,
    ) {}
}
