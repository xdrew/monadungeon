<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * A reincarnating hero lost the last HP and must choose a healing fountain (the turn stays open).
 *
 * @psalm-immutable
 */
final readonly class ReincarnationRequired implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
    ) {}
}
