<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Karak: Regent — the duel winner takes one item from the loser (dispatched before the inventory moves,
 * so the Field does not drop the item on the arena).
 *
 * @psalm-immutable
 */
final readonly class DuelLootTaken implements Event
{
    public function __construct(
        public Uuid $duelId,
        public Uuid $gameId,
        public Uuid $winnerId,
        public Uuid $loserId,
        public Item $item,
        // a full pack kept its own items: the taken item leaves the loser and stays on the arena
        public bool $leftOnArena = false,
    ) {}
}
