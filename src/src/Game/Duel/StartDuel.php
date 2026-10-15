<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Karak: Regent — create the duel of a Battle Arena (BeginDuel then starts the challenger's roll).
 *
 * @psalm-immutable
 * @implements Message<Duel>
 */
final readonly class StartDuel implements Message
{
    public function __construct(
        public Uuid $duelId,
        public Uuid $gameId,
        public Uuid $turnId,
        public FieldPlace $arenaPosition,
        public Uuid $challengerId,
        public Uuid $opponentId,
        public bool $firstStep,
    ) {}
}
