<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<Game>
 */
final readonly class CreateGame implements Message
{
    public function __construct(
        public Uuid $gameId,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
        public int $deckSize = 88,
        /**
         * Karak: Regent expansion — adds the Lord Karak rooms, the new monsters and heroes.
         */
        public bool $regent = false,
        /**
         * Karak: Regent, Lord Karak variant — the weakest hero becomes Lord Karak (needs the expansion).
         */
        public bool $lordKarak = false,
        /**
         * Heroes are dealt at random: a player who joins without a hero gets a free one (AI players always do).
         */
        public bool $randomHeroes = false,
    ) {}
}
