<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<array<Uuid>>
 */
final readonly class GetActivePlayers implements Message
{
    public function __construct(
        public Uuid $gameId,
    ) {}
}
