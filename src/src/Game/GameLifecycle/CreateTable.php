<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * An online table: a game in the lobby that players join from their own browsers. The host takes the first seat
 * with a separate AddPlayer once the table exists.
 *
 * @psalm-immutable
 *
 * @implements Message<Game>
 */
final readonly class CreateTable implements Message
{
    public const int MIN_SEATS = 2;
    public const int MAX_SEATS = 5;

    public function __construct(
        public Uuid $gameId,
        public string $hostAccountId,
        public int $seats = 4,
        public bool $regent = false,
        public bool $lordKarak = false,
        public bool $randomHeroes = false,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
        public int $deckSize = 88,
    ) {}
}
