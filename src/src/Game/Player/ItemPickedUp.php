<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Field\FieldPlace;
use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Event that's dispatched when a player picks up an item from the field.
 */
final readonly class ItemPickedUp implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Item $item,
        public FieldPlace $position,
    ) {}
}
