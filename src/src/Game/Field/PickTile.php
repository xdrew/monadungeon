<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<Tile>
 */
final readonly class PickTile implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $tileId,
        public Uuid $playerId,
        public Uuid $turnId,
        public TileSide $requiredOpenSide,
    ) {}
}
