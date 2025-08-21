<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Event dispatched when a battle is finalized with selected consumables.
 * @psalm-immutable
 */
final readonly class BattleFinalized implements Event
{
    /**
     * @param array<Item> $finalUsedItems
     * @param array<string> $selectedConsumableIds
     */
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public Uuid $playerId,
        public array $finalUsedItems,
        public int $finalTotalDamage,
        public array $selectedConsumableIds,
    ) {}
}
