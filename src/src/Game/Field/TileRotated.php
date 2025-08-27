<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 * @implements Event<void>
 */
final readonly class TileRotated implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $tileId,
        public TileOrientation $orientation,
    ) {}
}