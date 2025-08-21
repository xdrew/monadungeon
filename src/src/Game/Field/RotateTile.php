<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<void>
 */
final readonly class RotateTile implements Message
{
    public function __construct(
        public Uuid $tileId,
        public TileSide $topSide,
        public TileSide $requiredOpenSide,
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
    ) {}
}
