<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * The host left a table that never started: the game is over without a winner and counts nowhere.
 *
 * @psalm-immutable
 */
final readonly class TableClosed implements Event
{
    public function __construct(
        public Uuid $gameId,
    ) {}
}
