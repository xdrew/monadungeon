<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Karak: Regent — a human hero activated a Battle Arena and has to choose a rival.
 *
 * @psalm-immutable
 */
final readonly class ArenaEntered implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public FieldPlace $position,
    ) {}
}
