<?php

declare(strict_types=1);

namespace App\Game\Movement\Commands;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Baron Markul "Lightning Attack": teleport onto a tile with a monster and fight it, for all 4 moves.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class LightningAttack implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public FieldPlace $targetPosition,
    ) {}
}
