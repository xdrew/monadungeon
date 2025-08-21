<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;
use Telephantast\MessageBus\MessageBus;

/**
 * @psalm-immutable
 *
 * @implements Message<array>
 */
final readonly class GetAvailablePlacesForPlayer implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public ?MessageBus $messageBus = null,
    ) {}
}
