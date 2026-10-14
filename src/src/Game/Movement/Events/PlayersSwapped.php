<?php

declare(strict_types=1);

namespace App\Game\Movement\Events;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Warlock "Magic Swap": the hero now stands on $playerTo, the other player on $playerFrom.
 */
final readonly class PlayersSwapped implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $targetPlayerId,
        public FieldPlace $playerFrom,
        public FieldPlace $playerTo,
        public \DateTimeImmutable $swappedAt = new \DateTimeImmutable(),
    ) {}
}
