<?php

declare(strict_types=1);

namespace App\Game\Turn;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<GameTurn>
 */
final readonly class StartTurn implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public int $turnNumber,
        public Uuid $turnId,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
    ) {}
}
