<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Game\Field\FieldPlace;
use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class MonsterDefeated implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public FieldPlace $position,
        public Item $monster,
    ) {}
}
