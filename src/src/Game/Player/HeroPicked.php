<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class HeroPicked implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public HeroType $hero,
    ) {}
}
