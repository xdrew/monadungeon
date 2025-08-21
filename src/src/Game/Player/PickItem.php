<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Field\FieldPlace;
use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<Item|null>
 */
final readonly class PickItem implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public FieldPlace $position,
        public ?Uuid $itemIdToReplace = null,
    ) {}
}
