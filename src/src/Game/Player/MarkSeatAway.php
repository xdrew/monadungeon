<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * An online seat let its turn run out: the AI plays its turns until its player comes back.
 * Answers whether the flag changed.
 *
 * @psalm-immutable
 *
 * @implements Message<bool>
 */
final readonly class MarkSeatAway implements Message
{
    public function __construct(
        public Uuid $playerId,
        public Uuid $gameId,
    ) {}
}
