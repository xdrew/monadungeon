<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class BattleDiceRerolled implements Event
{
    /**
     * @param array<int> $firstDiceResults
     * @param array<int> $diceResults
     */
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public Uuid $playerId,
        public array $firstDiceResults,
        public array $diceResults,
    ) {}
}
