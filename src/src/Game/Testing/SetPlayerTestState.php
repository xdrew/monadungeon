<?php

declare(strict_types=1);

namespace App\Game\Testing;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @implements Message<void>
 */
final readonly class SetPlayerTestState implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public PlayerTestConfig $config,
    ) {}
}
