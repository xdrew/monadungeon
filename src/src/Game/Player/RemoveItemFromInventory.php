<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Command to remove an item from a player's inventory.
 * @implements Message<void>
 */
final readonly class RemoveItemFromInventory implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $itemId,
    ) {}
}
