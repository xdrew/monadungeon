<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Nothing happened at a table in the lobby for a long while (no one joined, left or was seated): it is closed as
 * if its host had left. True when it was closed.
 *
 * @psalm-immutable
 *
 * @implements Message<bool>
 */
final readonly class CloseIdleTable implements Message
{
    public const int IDLE_MINUTES = 60;

    public function __construct(
        public Uuid $gameId,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
    ) {}
}
