<?php

declare(strict_types=1);

namespace App\Api\Game\PickItem;

use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public ?Item $item = null,
        public bool $inventoryFull = false,
        public ?string $itemCategory = null,
        public ?int $maxItemsInCategory = null,
        public ?array $currentInventory = null,
        public bool $missingKey = false,
        public ?string $chestType = null,
        public bool $itemReplaced = false,
    ) {}
}
