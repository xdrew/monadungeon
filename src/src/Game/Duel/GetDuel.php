<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<Duel>
 */
final readonly class GetDuel implements Message
{
    public function __construct(
        public Uuid $duelId,
    ) {}
}
