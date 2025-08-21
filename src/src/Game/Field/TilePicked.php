<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class TilePicked implements Event
{
    public function __construct(
        public Uuid $tileId,
        public TileOrientation $orientation,
        public bool $room,
    ) {}
}
