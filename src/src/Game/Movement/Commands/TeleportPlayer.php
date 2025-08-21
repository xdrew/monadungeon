<?php

declare(strict_types=1);

namespace App\Game\Movement\Commands;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class TeleportPlayer implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public FieldPlace $to,
    ) {}
}
