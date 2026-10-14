<?php

declare(strict_types=1);

namespace App\Game\Battle;

// BattleResult is now in the same namespace
use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class BattleCompleted implements Event
{
    /**
     * @param array<int> $diceResults
     * @param array<Item>|null $usedItems
     * @param array<Item>|null $availableConsumables
     * @param bool $canReroll Hero may still use "Double Attack" on this roll
     * @param bool $rerolled This result comes from a "Double Attack" reroll
     * @param array<int>|null $firstDiceResults The discarded first roll when rerolled
     */
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public Uuid $playerId,
        public BattleResult $result,
        public array $diceResults,
        public int $diceRollDamage,
        public int $itemDamage,
        public int $totalDamage,
        public int $monsterHP,
        public ?array $usedItems = null,
        public ?array $availableConsumables = null,
        public bool $needsConsumableConfirmation = false,
        public bool $itemPickedUp = false,
        public bool $canReroll = false,
        public bool $rerolled = false,
        public ?array $firstDiceResults = null,
        public bool $tieIsWin = false,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}
}
