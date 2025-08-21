<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<Game>
 */
final readonly class CreateGame implements Message
{
    public function __construct(
        public Uuid $gameId,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
        public int $deckSize = 88,
    ) {}
}
