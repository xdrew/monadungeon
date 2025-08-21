<?php

declare(strict_types=1);

namespace App\Game\Movement\Events;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class PlayerMoved implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public FieldPlace $fromPosition,
        public FieldPlace $toPosition,
        public \DateTimeImmutable $movedAt,
        public bool $isBattleReturn = false,
        public bool $isTilePlacementMove = false,
    ) {}
}
