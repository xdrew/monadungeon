<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class PlayerReady implements Event
{
    public function __construct(
        public Uuid $playerId,
        public Uuid $gameId,
    ) {}
}
