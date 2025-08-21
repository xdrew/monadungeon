<?php

declare(strict_types=1);

namespace App\Game\Movement;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<array<string, FieldPlace>>
 */
final readonly class GetAllPlayerPositions implements Message
{
    public function __construct(
        public Uuid $gameId,
    ) {}
}
