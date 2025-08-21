<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Event that's dispatched when an item is added to a player's inventory.
 */
final readonly class ItemAddedToInventory implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Item $item,
    ) {}
}
