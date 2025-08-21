<?php

declare(strict_types=1);

namespace App\Game\Turn;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class TurnActionPerformed implements Event
{
    public function __construct(
        public Uuid $turnId,
        public Uuid $gameId,
        public Uuid $playerId,
        public TurnAction $action,
        public \DateTimeImmutable $performedAt,
        public ?Uuid $tileId = null,
        public ?array $additionalData = null,
    ) {}
}
