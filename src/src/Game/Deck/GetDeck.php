<?php

declare(strict_types=1);

namespace App\Game\Deck;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<Deck>
 */
final readonly class GetDeck implements Message
{
    public function __construct(
        public Uuid $gameId,
    ) {}
}
