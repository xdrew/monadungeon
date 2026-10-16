<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * Karak: Regent — both totals are known: the result, the HP lost and the loot (taken, or still to pick).
 *
 * @psalm-immutable
 */
final readonly class DuelResolved implements Event
{
    /**
     * @param 'challenger'|'opponent'|'tie' $result
     * @param array{itemId: string, type: string, name: string}|null $loot
     * @param list<array{itemId: string, type: string, name: string, swap: bool}> $lootCandidates
     */
    public function __construct(
        public Uuid $duelId,
        public Uuid $gameId,
        public string $phase,
        public string $result,
        public ?Uuid $loserId,
        public ?array $loot,
        public array $lootCandidates = [],
        public ?string $reincarnatedTo = null,
    ) {}
}
