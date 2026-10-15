<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Karak: Regent — roll the challenger's side. Separate from StartDuel because the Duel aggregate can only
 * receive the follow-up events (an automatic side completes at once) after it has been created and stored.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class BeginDuel implements Message
{
    public function __construct(
        public Uuid $duelId,
        public Uuid $gameId,
    ) {}
}
