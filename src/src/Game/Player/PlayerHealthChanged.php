<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class PlayerHealthChanged implements Message
{
    public function __construct(
        public Uuid $playerId,
        public Uuid $gameId,
        public int $healthChange,
    ) {}
}
