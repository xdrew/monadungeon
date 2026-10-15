<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Karak: Regent — the human challenger who won the duel picks the item to take from the loser.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class TakeDuelLoot implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public Uuid $duelId,
        public Uuid $itemId,
    ) {}
}
