<?php

declare(strict_types=1);

namespace App\Game\Movement\Events;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;

final readonly class MovementBlocked
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public FieldPlace $from,
        public FieldPlace $attemptedTo,
        public string $reason,
    ) {}
}
