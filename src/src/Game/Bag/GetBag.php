<?php

declare(strict_types=1);

namespace App\Game\Bag;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<Bag>
 */
final readonly class GetBag implements Message
{
    public function __construct(public Uuid $gameId) {}
}
