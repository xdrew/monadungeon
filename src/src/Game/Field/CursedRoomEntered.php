<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Karak: Regent — a hero entered a Cursed Room and rolled the die: 1–3 curses him, 4–6 lets him go.
 *
 * @psalm-immutable
 */
final readonly class CursedRoomEntered implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public FieldPlace $position,
        public int $roll,
        public bool $cursed,
        public ?Uuid $turnId = null,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}
}
