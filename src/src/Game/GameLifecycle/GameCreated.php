<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class GameCreated implements Event
{
    public function __construct(
        public Uuid $gameId,
        public \DateTimeImmutable $gameCreateTime,
        public int $deckSize = 100,
    ) {}
}
