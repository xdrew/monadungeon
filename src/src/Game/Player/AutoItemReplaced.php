<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Event dispatched when an item is automatically replaced without player intervention.
 * @psalm-immutable
 */
final readonly class AutoItemReplaced implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Item $oldItem,
        public Item $newItem,
    ) {}
}
