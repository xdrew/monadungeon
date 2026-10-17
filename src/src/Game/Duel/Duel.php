<?php

declare(strict_types=1);

namespace App\Game\Duel;

use App\Game\Battle\FinalizeBattle;
use App\Game\Battle\StartBattle;
use App\Game\Field\AutoReincarnate;
use App\Game\Field\FieldPlace;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemType;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\AddItemToInventory;
use App\Game\Player\GetPlayer;
use App\Game\Player\Player;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Player\ReplaceInventoryItem;
use App\Game\Turn\EndTurn;
use App\Game\Turn\Error\NotYourTurnException;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Infrastructure\Doctrine\AggregateRoot;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Telephantast\MessageBus\EntityHandler\FindBy;
use Telephantast\MessageBus\EntityHandler\Property;
use Telephantast\MessageBus\Handler\Mapping\Handler;
use Telephantast\MessageBus\MessageContext;

/**
 * Karak: Regent — a duel on a Battle Arena. Both heroes choose their spells before any die is rolled (the
 * challenger first, then the summoned hero), then two Battles in duel mode: the challenger rolls, then the
 * summoned hero rolls against the challenger's total. Higher total wins (a hero who wins ties wins an
 * equal total, both or neither → tie); the loser loses 1 HP and the winner takes one item the winner has room for.
 */
#[Entity]
#[Table(schema: 'duel')]
#[FindBy(['duelId' => new Property('duelId')])]
class Duel extends AggregateRoot
{
    public const string RESULT_CHALLENGER = 'challenger';
    public const string RESULT_OPPONENT = 'opponent';
    public const string RESULT_TIE = 'tie';

    #[Column(type: Types::STRING, enumType: DuelPhase::class)]
    private DuelPhase $phase = DuelPhase::CHALLENGER_PREPARE;

    /**
     * The spells (item ids) and the sacrifice each side chose before the roll.
     *
     * @var array{consumableIds: list<string>, sacrifice: bool}|null
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $challengerPrep = null;

    /** @var array{consumableIds: list<string>, sacrifice: bool}|null */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $opponentPrep = null;

    #[Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $begun = false;

    #[Column(type: Types::BOOLEAN)]
    private bool $challengerIsAi = false;

    #[Column(type: Types::BOOLEAN)]
    private bool $opponentIsAi = false;

    #[Column(type: Types::BOOLEAN)]
    private bool $firstStep = false;

    #[Column(type: Types::INTEGER, nullable: true)]
    private ?int $challengerTotal = null;

    #[Column(type: Types::INTEGER, nullable: true)]
    private ?int $opponentTotal = null;

    /** @var array<string, mixed>|null */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $challengerDetails = null;

    /** @var array<string, mixed>|null */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $opponentDetails = null;

    #[Column(type: Types::STRING, nullable: true)]
    private ?string $result = null;

    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $loserId = null;

    #[Column(type: Types::STRING, nullable: true)]
    private ?string $reincarnatedTo = null;

    /** @var array{itemId: string, type: string, name: string}|null */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $loot = null;

    /**
     * The loser's items the winner has room for (full item arrays, for the LOOT phase).
     *
     * @var list<array<string, mixed>>|null
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $lootCandidates = null;

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $duelId,
        #[Column(type: UuidType::class)]
        private readonly Uuid $gameId,
        #[Column(type: UuidType::class)]
        private readonly Uuid $turnId,
        #[Column(type: Types::STRING)]
        private readonly string $arenaPosition,
        #[Column(type: UuidType::class)]
        private readonly Uuid $challengerId,
        #[Column(type: UuidType::class)]
        private readonly Uuid $opponentId,
        #[Column(type: UuidType::class)]
        private readonly Uuid $challengerBattleId,
        #[Column(type: UuidType::class)]
        private readonly Uuid $opponentBattleId,
    ) {}

    #[Handler]
    public static function start(StartDuel $command, MessageContext $messageContext): self
    {
        $duel = new self(
            duelId: $command->duelId,
            gameId: $command->gameId,
            turnId: $command->turnId,
            arenaPosition: $command->arenaPosition->toString(),
            challengerId: $command->challengerId,
            opponentId: $command->opponentId,
            challengerBattleId: Uuid::v7(),
            opponentBattleId: Uuid::v7(),
        );
        $duel->challengerIsAi = $messageContext->dispatch(new GetPlayer($command->challengerId))->isAi();
        $duel->opponentIsAi = $messageContext->dispatch(new GetPlayer($command->opponentId))->isAi();
        $duel->firstStep = $command->firstStep;

        $messageContext->dispatch(new DuelStarted(
            duelId: $duel->duelId,
            gameId: $duel->gameId,
            turnId: $duel->turnId,
            arenaPosition: $command->arenaPosition,
            challengerId: $duel->challengerId,
            opponentId: $duel->opponentId,
            challengerBattleId: $duel->challengerBattleId,
            opponentBattleId: $duel->opponentBattleId,
        ));

        return $duel;
    }

    /**
     * Item priority for an automatic pick: the most valuable treasure, then the strongest weapon,
     * then Frost Fist > fireball > Dark Thorn > teleport, then a key.
     */
    public static function lootScore(Item $item): int
    {
        return match ($item->type->getCategory()) {
            ItemCategory::TREASURE => 1000 + $item->treasureValue,
            ItemCategory::WEAPON => 500 + $item->type->getDamage(),
            ItemCategory::SPELL => 100 + match ($item->type) {
                ItemType::FROST_FIST => 4,
                ItemType::FIREBALL => 3,
                ItemType::DARK_THORN => 2,
                default => 1,
            },
            default => 10,
        };
    }

    /**
     * @return array{itemId: string, type: string, name: string}
     */
    private static function summary(Item $item): array
    {
        return [
            'itemId' => $item->itemId->toString(),
            'type' => $item->type->value,
            'name' => $item->name->value,
        ];
    }

    /**
     * The winner's own item that the loot replaces: the same slot kind (Hanna's daggers may sit in a spell slot).
     */
    private static function ownItemOfSlot(Player $winner, Item $loot, Uuid $itemId): ?Item
    {
        $slots = [$loot->type->getCategory()->value];
        if ($loot->type === ItemType::DAGGER && $winner->getSkills()?->throwsDaggers() === true) {
            $slots[] = ItemCategory::SPELL->value;
        }
        foreach ($slots as $slot) {
            foreach ($winner->getInventory()[$slot] ?? [] as $own) {
                $own = Item::fromAnything($own);
                if ($own->itemId->equals($itemId)) {
                    return $own;
                }
            }
        }

        return null;
    }

    /**
     * The duel opens with the challenger choosing his spells; a side nobody answers for chooses at once.
     */
    #[Handler]
    public function begin(BeginDuel $command, MessageContext $messageContext): void
    {
        if ($this->begun) {
            return;
        }
        $this->begun = true;
        $this->phase = DuelPhase::CHALLENGER_PREPARE;

        if ($this->challengerIsAi) {
            $this->prepareSide(DuelSide::CHALLENGER, $this->aiPreset($this->challengerId, $this->opponentId, null, $messageContext), $messageContext);
        }
    }

    /**
     * A human hero of the duel chooses his spells (and the sacrifice) before the roll.
     *
     * @throws \DomainException
     * @throws NotYourTurnException
     */
    #[Handler]
    public function prepare(PrepareDuelSide $command, MessageContext $messageContext): void
    {
        $role = match (true) {
            $this->phase === DuelPhase::CHALLENGER_PREPARE && $command->playerId->equals($this->challengerId) => DuelSide::CHALLENGER,
            $this->phase === DuelPhase::OPPONENT_PREPARE && $command->playerId->equals($this->opponentId) => DuelSide::OPPONENT,
            default => throw new \DomainException('It is not your choice now'),
        };
        if (!$this->turnId->equals($command->turnId)) {
            throw new \DomainException('Invalid turn ID');
        }
        $currentTurnId = $messageContext->dispatch(new GetCurrentTurn($this->gameId));
        if ($currentTurnId === null || !$currentTurnId->equals($this->turnId)) {
            throw new NotYourTurnException();
        }

        $player = $messageContext->dispatch(new GetPlayer($command->playerId));
        $spells = [];
        foreach ($player->getConsumableItems() as $item) {
            $item = Item::fromAnything($item);
            if ($item->type->getDamage() > 0) {
                $spells[$item->itemId->toString()] = $item;
            }
        }
        $ids = array_values(array_unique($command->consumableIds));
        foreach ($ids as $id) {
            if (!isset($spells[$id])) {
                throw new \DomainException('Only your own damage spells can be spent');
            }
        }
        if ($command->sacrifice && !($player->getSkills()?->sacrificesHp() === true && $player->getHP() > 0)) {
            throw new \DomainException($player->isCursed()
                ? 'A cursed hero has no skills: no sacrifice'
                : 'Only the Warlock sacrifices HP');
        }

        $this->prepareSide($role, ['consumableIds' => $ids, 'sacrifice' => $command->sacrifice], $messageContext);
    }

    #[Handler]
    public function onDuelSideCompleted(DuelSideCompleted $event, MessageContext $messageContext): void
    {
        if ($event->role === DuelSide::CHALLENGER) {
            if ($this->phase !== DuelPhase::CHALLENGER_ROLL) {
                return;
            }
            $this->challengerTotal = $event->total;
            $this->challengerDetails = $event->details();
            $this->phase = DuelPhase::OPPONENT_ROLL;

            // The summoned hero rolls against the challenger's total (a summoned Oracle always gets +1)
            $opponent = $messageContext->dispatch(new GetPlayer($this->opponentId));
            $arena = FieldPlace::fromString($this->arenaPosition);
            $messageContext->dispatch(new StartBattle(
                battleId: $this->opponentBattleId,
                gameId: $this->gameId,
                playerId: $this->opponentId,
                turnId: $this->turnId,
                monster: Item::createDuelTarget($event->total),
                fromPosition: $arena,
                toPosition: $arena,
                duel: new DuelSide(
                    duelId: $this->duelId,
                    role: DuelSide::OPPONENT,
                    toBeat: $event->total,
                    farseeing: $opponent->getSkills()?->fightsBetterOnFirstMove() === true,
                    // A summoned human decides on his spells himself, also against an AI (its turn waits for him);
                    // only a human away from the table is rolled automatically
                    auto: $this->opponentIsAi || $opponent->isAway(),
                    opponentId: $this->challengerId,
                    preset: $this->opponentPrep ?? ['consumableIds' => [], 'sacrifice' => false],
                ),
            ));

            return;
        }

        if ($this->phase !== DuelPhase::OPPONENT_ROLL) {
            return;
        }
        $this->opponentTotal = $event->total;
        $this->opponentDetails = $event->details();
        $this->resolve($messageContext);
    }

    /**
     * The human challenger who won picks the item to take from the loser.
     *
     * @throws \DomainException
     * @throws NotYourTurnException
     */
    #[Handler]
    public function takeLoot(TakeDuelLoot $command, MessageContext $messageContext): void
    {
        if ($this->phase !== DuelPhase::LOOT) {
            throw new \DomainException('No loot to take');
        }
        $winnerId = $this->winnerId();
        if ($winnerId === null || !$winnerId->equals($command->playerId)) {
            throw new \DomainException('Only the winner of the duel takes the loot');
        }
        if (!$this->turnId->equals($command->turnId)) {
            throw new \DomainException('Invalid turn ID');
        }
        // the winner takes it in the duel's own turn: the challenger, or the summoned hero (an AI's turn waits)
        $currentTurnId = $messageContext->dispatch(new GetCurrentTurn($this->gameId));
        if ($currentTurnId === null || !$currentTurnId->equals($this->turnId)) {
            throw new NotYourTurnException();
        }

        $item = null;
        foreach ($this->lootCandidates ?? [] as $candidate) {
            $candidateItem = Item::fromArray($candidate);
            if ($candidateItem->itemId->equals($command->itemId)) {
                $item = $candidateItem;
                break;
            }
        }
        if ($item === null) {
            throw new \DomainException('Unknown item');
        }
        // A full pack takes the item in exchange for one of its own, which is left on the arena
        $winner = $messageContext->dispatch(new GetPlayer($winnerId));
        $dropped = null;
        if (!$winner->hasRoomFor($item->type)) {
            if ($command->replaceItemId === null) {
                throw new \DomainException('No room for this item: choose one of yours to leave');
            }
            $dropped = self::ownItemOfSlot($winner, $item, $command->replaceItemId);
            if ($dropped === null) {
                throw new \DomainException('The item to leave must be yours and of the same kind');
            }
        }

        $this->transferLoot($item, $messageContext, $dropped);
        $this->phase = DuelPhase::DONE;

        // The turn log belongs to the turn's hero (the challenger); `by` is who took it (the summoned winner too)
        try {
            $messageContext->dispatch(new PerformTurnAction(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->challengerId,
                action: TurnAction::DUEL_LOOT,
                additionalData: [
                    'duelId' => $this->duelId->toString(),
                    'item' => $item->type->value,
                    'itemId' => $item->itemId->toString(),
                    'from' => $this->loserId?->toString(),
                    'by' => $command->playerId->toString(),
                    'dropped' => $dropped?->type->value,
                ],
            ));
        } catch (\RuntimeException) {
            // The turn log is bookkeeping: the loot changed hands either way
        }

        $this->endChallengersTurn($messageContext);
    }

    /**
     * The turn ended with the duel still open: pending rolls go through without consumables, the loot is auto-picked.
     */
    #[Handler]
    public function settle(SettleDuel $command, MessageContext $messageContext): void
    {
        // a side that did not choose its spells in time spends none
        if ($this->begun && $this->phase === DuelPhase::CHALLENGER_PREPARE) {
            $this->prepareSide(DuelSide::CHALLENGER, ['consumableIds' => [], 'sacrifice' => false], $messageContext);
        }
        if ($this->begun && $this->phase === DuelPhase::OPPONENT_PREPARE) {
            $this->prepareSide(DuelSide::OPPONENT, ['consumableIds' => [], 'sacrifice' => false], $messageContext);
        }

        if ($this->phase === DuelPhase::CHALLENGER_ROLL && $this->begun) {
            $messageContext->dispatch(new FinalizeBattle(
                battleId: $this->challengerBattleId,
                gameId: $this->gameId,
                playerId: $this->challengerId,
                turnId: $this->turnId,
                selectedConsumableIds: [],
            ));
        }

        // The challenger's side may have started the opponent's roll just now (re-read the phase)
        if ($this->phase === DuelPhase::OPPONENT_ROLL) {
            $messageContext->dispatch(new FinalizeBattle(
                battleId: $this->opponentBattleId,
                gameId: $this->gameId,
                playerId: $this->opponentId,
                turnId: $this->turnId,
                selectedConsumableIds: [],
            ));
        }

        if ($this->phase === DuelPhase::LOOT) {
            $winnerId = $this->winnerId();
            $item = $winnerId !== null
                ? $this->autoPick($this->candidateItems(), $messageContext->dispatch(new GetPlayer($winnerId)))
                : null;
            if ($item !== null) {
                $this->transferLoot($item, $messageContext);
            }
            $this->phase = DuelPhase::DONE;
        }
    }

    #[Handler]
    public function get(GetDuel $query): self
    {
        return $this;
    }

    public function getDuelId(): Uuid
    {
        return $this->duelId;
    }

    public function getPhase(): DuelPhase
    {
        return $this->phase;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function getLoot(): ?array
    {
        return $this->loot;
    }

    public function getChallengerBattleId(): Uuid
    {
        return $this->challengerBattleId;
    }

    public function getOpponentBattleId(): Uuid
    {
        return $this->opponentBattleId;
    }

    /**
     * A side's choice is kept; the summoned hero chooses next (at once if nobody answers for him), then the
     * challenger rolls.
     *
     * @param array{consumableIds: list<string>, sacrifice: bool} $preset
     */
    private function prepareSide(string $role, array $preset, MessageContext $messageContext): void
    {
        if ($role === DuelSide::CHALLENGER) {
            $this->challengerPrep = $preset;
            $this->phase = DuelPhase::OPPONENT_PREPARE;
        } else {
            $this->opponentPrep = $preset;
            $this->phase = DuelPhase::CHALLENGER_ROLL;
        }
        $player = $messageContext->dispatch(new GetPlayer($role === DuelSide::CHALLENGER ? $this->challengerId : $this->opponentId));
        $messageContext->dispatch(new DuelSidePrepared(
            duelId: $this->duelId,
            gameId: $this->gameId,
            role: $role,
            items: $this->presetSummary($player, $preset),
            sacrifice: $preset['sacrifice'],
            phase: $this->phase->value,
        ));

        if ($role === DuelSide::CHALLENGER) {
            if ($this->opponentIsAi || $messageContext->dispatch(new GetPlayer($this->opponentId))->isAway()) {
                $this->prepareSide(DuelSide::OPPONENT, $this->aiPreset($this->opponentId, $this->challengerId, $this->challengerPrep, $messageContext), $messageContext);
            }

            return;
        }
        $this->rollChallenger($messageContext);
    }

    /**
     * The challenger rolls first (Oracle "Farseeing" +1 when the arena was the first step of her turn).
     */
    private function rollChallenger(MessageContext $messageContext): void
    {
        $challenger = $messageContext->dispatch(new GetPlayer($this->challengerId));
        $arena = FieldPlace::fromString($this->arenaPosition);

        $messageContext->dispatch(new StartBattle(
            battleId: $this->challengerBattleId,
            gameId: $this->gameId,
            playerId: $this->challengerId,
            turnId: $this->turnId,
            monster: Item::createDuelTarget(0),
            fromPosition: $arena,
            toPosition: $arena,
            duel: new DuelSide(
                duelId: $this->duelId,
                role: DuelSide::CHALLENGER,
                toBeat: null,
                farseeing: $this->firstStep && $challenger->getSkills()?->fightsBetterOnFirstMove() === true,
                auto: $this->challengerIsAi,
                opponentId: $this->opponentId,
                preset: $this->challengerPrep ?? ['consumableIds' => [], 'sacrifice' => false],
            ),
        ));
    }

    /**
     * What a side nobody answers for spends before the roll: the fewest damage spells (strongest first) that put
     * its expected strength (weapons + 7) above the rival's (weapons + 7 + the spells the rival chose); none
     * when even all of them would not.
     *
     * @param array{consumableIds: list<string>, sacrifice: bool}|null $rivalPrep
     * @return array{consumableIds: list<string>, sacrifice: bool}
     */
    private function aiPreset(Uuid $playerId, Uuid $rivalId, ?array $rivalPrep, MessageContext $messageContext): array
    {
        $self = $messageContext->dispatch(new GetPlayer($playerId));
        $rival = $messageContext->dispatch(new GetPlayer($rivalId));
        $rivalSpells = 0;
        foreach ($this->presetSummary($rival, $rivalPrep ?? ['consumableIds' => [], 'sacrifice' => false]) as $item) {
            $rivalSpells += ItemType::from($item['type'])->getDamage();
        }
        $needed = ($rival->calculateWeaponDamage() + 7 + $rivalSpells + 1) - ($self->calculateWeaponDamage() + 7);

        $spells = array_values(array_filter(
            array_map(static fn(Item|array $item): Item => Item::fromAnything($item), $self->getConsumableItems()),
            static fn(Item $item): bool => $item->type->getDamage() > 0,
        ));
        usort($spells, static fn(Item $a, Item $b): int => $b->type->getDamage() <=> $a->type->getDamage());
        $picked = [];
        $sum = 0;
        if ($needed > 0) {
            foreach ($spells as $spell) {
                $picked[] = $spell->itemId->toString();
                $sum += $spell->type->getDamage();
                if ($sum >= $needed) {
                    break;
                }
            }
            if ($sum < $needed) {
                $picked = [];
            }
        }

        return ['consumableIds' => $picked, 'sacrifice' => false];
    }

    /**
     * @param array{consumableIds: list<string>, sacrifice: bool} $preset
     * @return list<array{itemId: string, type: string, name: string}>
     */
    private function presetSummary(Player $player, array $preset): array
    {
        $items = [];
        foreach ($player->getConsumableItems() as $item) {
            $item = Item::fromAnything($item);
            if (\in_array($item->itemId->toString(), $preset['consumableIds'], true)) {
                $items[] = self::summary($item);
            }
        }

        return $items;
    }

    /**
     * The best item that fits the winner's pack (nobody is there to choose what to leave).
     *
     * @param list<Item> $candidates
     */
    private function autoPick(array $candidates, Player $winner): ?Item
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if (!$winner->hasRoomFor($candidate->type)) {
                continue;
            }
            if ($best === null || self::lootScore($candidate) > self::lootScore($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function resolve(MessageContext $messageContext): void
    {
        $challengerTotal = $this->challengerTotal ?? 0;
        $opponentTotal = $this->opponentTotal ?? 0;
        $challengerWinsTies = ($this->challengerDetails['tieIsWin'] ?? false) === true;
        $opponentWinsTies = ($this->opponentDetails['tieIsWin'] ?? false) === true;

        if ($challengerTotal > $opponentTotal) {
            $this->result = self::RESULT_CHALLENGER;
        } elseif ($opponentTotal > $challengerTotal) {
            $this->result = self::RESULT_OPPONENT;
        } elseif ($challengerWinsTies && !$opponentWinsTies) {
            $this->result = self::RESULT_CHALLENGER;
        } elseif ($opponentWinsTies && !$challengerWinsTies) {
            $this->result = self::RESULT_OPPONENT;
        } else {
            $this->result = self::RESULT_TIE;
        }

        $winnerId = $this->winnerId();
        $this->loserId = $this->result === self::RESULT_TIE ? null : ($this->result === self::RESULT_CHALLENGER ? $this->opponentId : $this->challengerId);

        if ($winnerId !== null && $this->loserId !== null) {
            $loserId = $this->loserId;
            $messageContext->dispatch(new ReducePlayerHP(
                playerId: $loserId,
                gameId: $this->gameId,
                amount: 1,
                turnId: $this->turnId,
            ));

            // A Warrior knocked out by the duel reincarnates at the nearest fountain right away
            if ($messageContext->dispatch(new GetPlayer($loserId))->isAwaitingReincarnation()) {
                $messageContext->dispatch(new AutoReincarnate(gameId: $this->gameId, playerId: $loserId));
                $this->reincarnatedTo = $messageContext->dispatch(new GetPlayerPosition($this->gameId, $loserId))->toString();
            }

            $winner = $messageContext->dispatch(new GetPlayer($winnerId));
            $candidates = $this->lootCandidatesFor($messageContext->dispatch(new GetPlayer($loserId)));
            $this->lootCandidates = array_map(static fn(Item $item): array => $item->toArray(), $candidates);

            $currentPlayerId = $messageContext->dispatch(new GetCurrentPlayer($this->gameId));
            if ($candidates === []) {
                $this->phase = DuelPhase::DONE;
            } elseif ($winner->isAi() || $winner->isAway() || $currentPlayerId === null) {
                // Nobody can answer for this winner right now: the best item that fits is taken automatically
                $item = $this->autoPick($candidates, $winner);
                if ($item !== null) {
                    $this->transferLoot($item, $messageContext);
                }
                $this->phase = DuelPhase::DONE;
            } else {
                $this->phase = DuelPhase::LOOT;
            }
        } else {
            $this->lootCandidates = [];
            $this->phase = DuelPhase::DONE;
        }

        $lootWinnerId = $this->phase === DuelPhase::LOOT ? $this->winnerId() : null;
        $messageContext->dispatch(new DuelResolved(
            duelId: $this->duelId,
            gameId: $this->gameId,
            phase: $this->phase->value,
            result: $this->result,
            loserId: $this->loserId,
            loot: $this->loot,
            lootCandidates: $lootWinnerId !== null
                ? $this->lootCandidatesSummary($messageContext->dispatch(new GetPlayer($lootWinnerId)))
                : [],
            reincarnatedTo: $this->reincarnatedTo,
        ));

        try {
            $messageContext->dispatch(new PerformTurnAction(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->challengerId,
                action: TurnAction::DUEL,
                additionalData: [
                    'duelId' => $this->duelId->toString(),
                    'arena' => $this->arenaPosition,
                    'challenger' => $this->challengerId->toString(),
                    'opponent' => $this->opponentId->toString(),
                    'challengerTotal' => $this->challengerTotal,
                    'opponentTotal' => $this->opponentTotal,
                    'challengerDetails' => $this->challengerDetails,
                    'opponentDetails' => $this->opponentDetails,
                    'result' => $this->result,
                    'loser' => $this->loserId?->toString(),
                    'loot' => $this->loot,
                    'reincarnatedTo' => $this->reincarnatedTo,
                ],
            ));
        } catch (\RuntimeException) {
            // The turn log is bookkeeping: the duel is resolved either way
        }

        // A duel ends the challenger's turn like any fight — once the loot, if any, has been taken
        if ($this->phase === DuelPhase::DONE) {
            $this->endChallengersTurn($messageContext);
        }
    }

    /**
     * The duel is over: so is the challenger's turn (unless the turn end is what settled the duel).
     */
    private function endChallengersTurn(MessageContext $messageContext): void
    {
        if ($messageContext->dispatch(new GetTurn($this->turnId))?->isEnded() !== false) {
            return;
        }
        error_log('INFO duel.turn_ended ' . json_encode(['duelId' => $this->duelId->toString(), 'challenger' => $this->challengerId->toString(), 'result' => $this->result]));
        $messageContext->dispatch(new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->challengerId,
        ));
    }

    /**
     * Everything the loser carries: what does not fit the winner's pack is taken in exchange (takeLoot).
     *
     * @return list<Item>
     */
    private function lootCandidatesFor(Player $loser): array
    {
        $candidates = [];
        foreach ($loser->getInventory() as $items) {
            foreach ($items as $item) {
                $candidates[] = Item::fromAnything($item);
            }
        }

        return $candidates;
    }

    /**
     * @return list<Item>
     */
    private function candidateItems(): array
    {
        return array_map(static fn(array $item): Item => Item::fromArray($item), $this->lootCandidates ?? []);
    }

    /**
     * @return list<array{itemId: string, type: string, name: string, swap: bool}>
     */
    private function lootCandidatesSummary(Player $winner): array
    {
        return array_map(
            static fn(Item $item): array => self::summary($item) + ['swap' => !$winner->hasRoomFor($item->type)],
            $this->candidateItems(),
        );
    }

    private function winnerId(): ?Uuid
    {
        return match ($this->result) {
            self::RESULT_CHALLENGER => $this->challengerId,
            self::RESULT_OPPONENT => $this->opponentId,
            default => null,
        };
    }

    /**
     * The event first (the Field marks the item so it is not dropped on the arena), then the inventories.
     */
    private function transferLoot(Item $item, MessageContext $messageContext, ?Item $dropped = null): void
    {
        $winnerId = $this->winnerId();
        $loserId = $this->loserId;
        if ($winnerId === null || $loserId === null) {
            return;
        }

        $messageContext->dispatch(new DuelLootTaken(
            duelId: $this->duelId,
            gameId: $this->gameId,
            winnerId: $winnerId,
            loserId: $loserId,
            item: $item,
        ));
        $messageContext->dispatch(new RemoveItemFromInventory(
            gameId: $this->gameId,
            playerId: $loserId,
            itemId: $item->itemId,
        ));
        if ($dropped !== null) {
            // the winner's own item leaves the pack and stays on the arena (the Field places what leaves a pack)
            $messageContext->dispatch(new ReplaceInventoryItem(
                playerId: $winnerId,
                gameId: $this->gameId,
                itemIdToReplace: $dropped->itemId,
                newItem: $item,
            ));
        } else {
            $messageContext->dispatch(new AddItemToInventory(
                playerId: $winnerId,
                gameId: $this->gameId,
                item: $item,
            ));
        }

        $this->loot = self::summary($item);
        $this->lootCandidates = [];
    }
}
