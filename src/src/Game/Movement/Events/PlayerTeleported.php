<?php

declare(strict_types=1);

namespace App\Game\Movement\Events;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

final readonly class PlayerTeleported implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public FieldPlace $from,
        public FieldPlace $to,
    ) {}
}
