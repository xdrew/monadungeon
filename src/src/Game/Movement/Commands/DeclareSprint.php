<?php

declare(strict_types=1);

namespace App\Game\Movement\Commands;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Hanna "Sprint": up to 8 moves this turn over discovered tiles only; declared at the start of the turn.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class DeclareSprint implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
    ) {}
}
