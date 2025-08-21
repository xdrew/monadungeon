<?php

declare(strict_types=1);

namespace App\Game\Deck;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class DeckCreated implements Event
{
    public function __construct(
        public Uuid $gameId,
        public int $roomCount,
    ) {}
}
