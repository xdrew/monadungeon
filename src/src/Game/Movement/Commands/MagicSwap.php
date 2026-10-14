<?php

declare(strict_types=1);

namespace App\Game\Movement\Commands;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Warlock "Magic Swap": trade places with another hero at the beginning of the turn, for the cost of all 4 moves.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class MagicSwap implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public Uuid $targetPlayerId,
    ) {}
}
