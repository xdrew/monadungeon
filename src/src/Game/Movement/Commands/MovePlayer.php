<?php

declare(strict_types=1);

namespace App\Game\Movement\Commands;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<bool>
 */
final readonly class MovePlayer implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public FieldPlace $fromPosition,
        public FieldPlace $toPosition,
        public bool $ignoreMonster = false,
        public bool $isBattleReturn = false,
        public bool $isTilePlacementMove = false,
    ) {}
}
