<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<array{weapons?: array<int, array{name: string}>, spells?: array<int, array{name: string}>, treasures?: array<int, array{name: string}>, keys?: array<int, array{name: string}>}>
 */
final readonly class QueryPlayerInventory implements Message
{
    public function __construct(
        public Uuid $playerId,
        public Uuid $gameId,
    ) {}
}
