<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Warrior "Reincarnation": teleport the fallen hero to the chosen healing fountain, restore HP and end the turn.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class Reincarnate implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public FieldPlace $targetPosition,
    ) {}
}
