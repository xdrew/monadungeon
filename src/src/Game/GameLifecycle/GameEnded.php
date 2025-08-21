<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class GameEnded implements Event
{
    /**
     * @param array<string, float> $scores Map of player ID to score
     */
    public function __construct(
        public Uuid $gameId,
        public ?Uuid $winnerId,
        public array $scores,
    ) {}
}
