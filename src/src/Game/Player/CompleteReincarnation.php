<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Restore all HP of a reincarnating hero after the healing fountain was chosen.
 *
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class CompleteReincarnation implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public FieldPlace $position,
    ) {}
}
