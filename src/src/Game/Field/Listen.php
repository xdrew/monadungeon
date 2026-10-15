<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Lady Lorraine "Listening": explore the adjacent tile just placed (its token is drawn) without entering it, for one move.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class Listen implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public FieldPlace $position,
    ) {}
}
