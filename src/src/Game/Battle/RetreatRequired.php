<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Baron Markul "Lightning Attack" was not won: he stays on the monster tile until he chooses where to retreat.
 *
 * @psalm-immutable
 */
final readonly class RetreatRequired implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public FieldPlace $position,
        public FieldPlace $teleportedFrom,
    ) {}
}
