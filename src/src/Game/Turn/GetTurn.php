<?php

declare(strict_types=1);

namespace App\Game\Turn;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<GameTurn|null>
 */
final readonly class GetTurn implements Message
{
    public function __construct(
        public Uuid $turnId,
    ) {}
}
