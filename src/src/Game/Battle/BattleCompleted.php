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
     * @param int $onesRerolled Swordsman "Combat Training": how many dice showing 1 were rerolled
     * @param bool $unstoppable Swordsman "Unstoppable": the turn continues after this battle
     * @param bool $canSacrificeHp Warlock "Sacrifice": the hero may still pay 1 HP for +1 strength
     * @param bool $hpSacrificed Warlock "Sacrifice": 1 HP was paid for +1 strength in this battle
     * @param bool $farseeing Oracle "Farseeing": +1 was added to this roll (battle on the first move of the turn)
     * @param int $berserkBonus Valduk "Berserk": strength added for his HP when the dice were rolled
     * @param int $onesAsSixes Lady Lorraine "Bear Attack": dice showing 1 that were counted as 6
     * @param bool $curseChoiceRequired Curse of Karak: a mummy was beaten, the (human) slayer must choose whom to curse
     * @param string|null $cursedPlayerId Curse of Karak: the player carrying the token right now
     * @param array{duelId: string, role: string, opponentId: string, toBeat: ?int}|null $duel Karak: Regent — a Battle Arena duel side
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
        public int $onesRerolled = 0,
        public bool $tieIsWin = false,
        public bool $unstoppable = false,
        public bool $canSacrificeHp = false,
        public bool $hpSacrificed = false,
        public bool $spellsKept = false,
        public bool $farseeing = false,
        public int $berserkBonus = 0,
        public int $onesAsSixes = 0,
        public bool $curseChoiceRequired = false,
        public ?string $cursedPlayerId = null,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
        public ?array $duel = null,
    ) {}
}
