<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Curse of Karak: the curse token left this player (healed at a fountain, or the token moved on).
 *
 * @psalm-immutable
 */
final readonly class CurseLifted implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public \DateTimeImmutable $liftedAt = new \DateTimeImmutable(),
    ) {}
}
