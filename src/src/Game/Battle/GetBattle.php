<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<Battle>
 */
final readonly class GetBattle implements Message
{
    public function __construct(
        public Uuid $battleId,
    ) {}
}
