<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Karak: Regent — a Lord Karak room (Battle Arena or Cursed Room) was placed; $count is how many are on the field now.
 * The Lord Karak variant reveals Lord Karak when the fifth one is discovered.
 *
 * @psalm-immutable
 */
final readonly class LordKarakRoomDiscovered implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public FieldPlace $position,
        public int $count,
    ) {}
}
