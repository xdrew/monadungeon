<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Command to finalize a battle with selected consumable items.
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class FinalizeBattle implements Message
{
    /**
     * @param array<string> $selectedConsumableIds Array of item IDs for consumables to use
     * @param bool $pickupItem Whether to pick up the item after winning the battle
     * @param ?string $replaceItemId ID of the item to replace if inventory is full (optional)
     */
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public array $selectedConsumableIds,
        public bool $pickupItem = false,
        public ?string $replaceItemId = null,
    ) {
        // We can't modify readonly properties in the constructor
    }
}
