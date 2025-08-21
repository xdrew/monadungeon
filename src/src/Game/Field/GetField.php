<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<Field>
 */
final readonly class GetField implements Message
{
    public function __construct(
        public Uuid $gameId,
    ) {}
}
