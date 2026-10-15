<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Warrior "Reincarnation" without a choice: a hero knocked out outside a normal fight (a Dark Thorn target,
 * a duel loser) is carried to the discovered healing fountain nearest to him.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class AutoReincarnate implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
    ) {}
}
