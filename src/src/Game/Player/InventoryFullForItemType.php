<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Event that's dispatched when there are no available slots for a specific item type.
 */
final readonly class InventoryFullForItemType implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public string $itemType,
    ) {}
}
