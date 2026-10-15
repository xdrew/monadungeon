<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Curse of Karak: a mummy was defeated, the slayer may place the curse token on another player.
 *
 * @psalm-immutable
 */
final readonly class MummyDefeated implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        /** the fight: a won fight is processed again when finalized, a second mummy in the same turn is a new fight */
        public ?Uuid $battleId = null,
        public \DateTimeImmutable $defeatedAt = new \DateTimeImmutable(),
    ) {}
}
