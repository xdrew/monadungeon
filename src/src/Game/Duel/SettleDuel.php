<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Karak: Regent — the turn ended with the duel still open: pending rolls go through without consumables,
 * a pending loot is picked automatically.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class SettleDuel implements Message
{
    public function __construct(
        public Uuid $duelId,
        public Uuid $gameId,
    ) {}
}
