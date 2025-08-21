<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<Uuid|null>
 */
final readonly class GetCurrentPlayer implements Message
{
    public function __construct(
        public Uuid $gameId,
    ) {}
}
