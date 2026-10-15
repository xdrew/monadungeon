<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Karak: Regent — the hero who first entered a Battle Arena summons another hero to a duel.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class ChooseDuelOpponent implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public Uuid $opponentId,
    ) {}
}
