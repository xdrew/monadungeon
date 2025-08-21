<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<array{playerId: string, hp: int, isStunned: bool, inventory: array{items: array, capacity: int, remaining: int}}>
 */
final readonly class GetPlayerStatus implements Message
{
    public function __construct(
        public Uuid $playerId,
    ) {}
}
