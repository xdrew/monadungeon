<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class DuelStarted implements Event
{
    public function __construct(
        public Uuid $duelId,
        public Uuid $gameId,
        public Uuid $turnId,
        public FieldPlace $arenaPosition,
        public Uuid $challengerId,
        public Uuid $opponentId,
        public Uuid $challengerBattleId,
        public Uuid $opponentBattleId,
    ) {}
}
