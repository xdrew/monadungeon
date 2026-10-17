<?php

declare(strict_types=1);

namespace App\Game\Turn;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<void>
 */
final readonly class EndTurn implements Message
{
    public function __construct(
        public Uuid $turnId,
        public Uuid $gameId,
        public Uuid $playerId,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
        /**
         * Ends the turn whatever state it is in (a seat played by the stand-in when the time ran out); a player's
         * own end of turn obeys the rules (e.g. a placed tile must be entered first).
         */
        public bool $force = false,
    ) {}
}
