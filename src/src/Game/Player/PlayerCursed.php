<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Curse of Karak: the curse token was placed on this player, the hero's skills are switched off.
 *
 * @psalm-immutable
 */
final readonly class PlayerCursed implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $byPlayerId,
        public \DateTimeImmutable $cursedAt = new \DateTimeImmutable(),
    ) {}
}
