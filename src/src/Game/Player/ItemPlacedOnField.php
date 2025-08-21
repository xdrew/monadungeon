<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Event that's dispatched when an item is placed on the field
 * This occurs when an item is removed from a player's inventory.
 */
final readonly class ItemPlacedOnField implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $itemId,
        public FieldPlace $fieldPlace,
    ) {}
}
