<?php

declare(strict_types=1);

namespace App\Game\Player\Error;

use App\Game\Item\Item;
use App\Game\Item\ItemCategory;

final class InventoryFullException extends \Exception
{
    public function __construct(
        public readonly Item $item,
        public readonly ItemCategory $category,
        public readonly int $maxItems,
        public readonly array $currentInventory,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Inventory for %s is full. Maximum is %d items.', $category->value, $maxItems),
            0,
            $previous,
        );
    }
}
