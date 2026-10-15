<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Baron Markul: the opened tile he retreats to after a Lightning Attack that was not won.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class ChooseRetreat implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public FieldPlace $position,
    ) {}
}
