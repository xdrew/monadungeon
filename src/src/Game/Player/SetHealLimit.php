<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Valduk "Berserk": heal only up to this many HP (null = heal fully).
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class SetHealLimit implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public ?int $hp,
    ) {}
}
