<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * The player of an away seat is back: their next turn is theirs again.
 * Answers whether the flag changed.
 *
 * @psalm-immutable
 *
 * @implements Message<bool>
 */
final readonly class MarkSeatBack implements Message
{
    public function __construct(
        public Uuid $playerId,
        public Uuid $gameId,
    ) {}
}
