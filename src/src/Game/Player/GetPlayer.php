<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<Player>
 */
final readonly class GetPlayer implements Message
{
    public function __construct(
        public Uuid $playerId,
    ) {}
}
