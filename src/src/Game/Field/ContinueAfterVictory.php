<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Valduk "Tireless": leave the loot of the monster just defeated on its tile and keep moving.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class ContinueAfterVictory implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
    ) {}
}
