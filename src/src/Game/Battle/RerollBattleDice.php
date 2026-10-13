<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Warrior "Double Attack": reroll the dice once in the current battle.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class RerollBattleDice implements Message
{
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
    ) {}
}
