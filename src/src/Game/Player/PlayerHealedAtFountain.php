<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class PlayerHealedAtFountain implements Event
{
    public function __construct(
        public Uuid $playerId,
        public Uuid $gameId,
        public FieldPlace $position,
        public \DateTimeImmutable $healedAt = new \DateTimeImmutable(),
    ) {}
}
