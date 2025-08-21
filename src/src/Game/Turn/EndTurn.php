<?php

declare(strict_types=1);

namespace App\Game\Turn;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<void>
 */
final readonly class EndTurn implements Message
{
    public function __construct(
        public Uuid $turnId,
        public Uuid $gameId,
        public Uuid $playerId,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
    ) {}
}
