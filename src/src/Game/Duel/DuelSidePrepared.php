<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Karak: Regent — a hero of a duel chose his spells before the roll; `phase` is where the duel goes next.
 *
 * @psalm-immutable
 */
final readonly class DuelSidePrepared implements Event
{
    /**
     * @param list<array{itemId: string, type: string, name: string}> $items
     */
    public function __construct(
        public Uuid $duelId,
        public Uuid $gameId,
        public string $role,
        public array $items,
        public bool $sacrifice,
        public string $phase,
    ) {}
}
