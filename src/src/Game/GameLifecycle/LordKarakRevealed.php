<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Game\Player\HeroType;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Karak: Regent, Lord Karak variant — the weakest hero became Lord Karak: fully healed, the curse removed,
 * with the abilities of the other heroes in the game (and his own ones with 2 players).
 *
 * @psalm-immutable
 */
final readonly class LordKarakRevealed implements Event
{
    /**
     * @param list<HeroType> $heroes the heroes whose abilities Lord Karak has
     * @param string $criterion what made him the weakest: treasure, weapons, items or roll
     */
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public array $heroes,
        public bool $keepsOwn,
        public string $criterion,
        public ?Uuid $turnId = null,
    ) {}
}
