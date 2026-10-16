<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class PlayerLeftTable implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
    ) {}
}
