<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Oracle "Fateweaver": pick which of the two drawn tokens is placed on the new room, the other goes back into the bag.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class ChooseFateweaverItem implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public Uuid $itemId,
    ) {}
}
