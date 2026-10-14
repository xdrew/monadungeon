<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Curse of Karak: the mummy slayer places the curse token on another player, or leaves it where it is (null target).
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class CursePlayer implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public ?Uuid $targetPlayerId = null,
    ) {}
}
