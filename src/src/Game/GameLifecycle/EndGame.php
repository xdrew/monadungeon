<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<void>
 */
final readonly class EndGame implements Message
{
    public function __construct(
        public Uuid $gameId,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
    ) {}
}
