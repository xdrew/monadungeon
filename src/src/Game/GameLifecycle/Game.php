<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Game\Battle\MummyDefeated;
use App\Game\Field\CursedRoomEntered;
use App\Game\Field\GetField;
use App\Game\Field\LordKarakRoomDiscovered;
use App\Game\GameLifecycle\Error\CannotAddPlayerToAlreadyFullGame;
use App\Game\GameLifecycle\Error\CannotAddPlayerToAlreadyPreparedGame;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Player\CurseLifted;
use App\Game\Player\GetActivePlayers;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\ItemAddedToInventory;
use App\Game\Player\Player;
use App\Game\Player\PlayerCursed;
use App\Game\Player\PlayerHealedAtFountain;
use App\Game\Player\ResetPlayerHP;
use App\Game\Testing\TestMode;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnEnded;
use App\Infrastructure\Doctrine\AggregateRoot;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidArrayJsonType;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\BooleanType;
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

#[Entity]
#[Table(schema: 'game')]
#[FindBy(['gameId' => new Property('gameId')])]
class Game extends AggregateRoot
{
    // Karak comes with 5 hero stands
    private const int MAX_PLAYERS_COUNT = 5;

    /**
     * Karak: Regent, Lord Karak variant — he enters when this many Lord Karak rooms are discovered.
     */
    private const int LORD_KARAK_ROOMS = 5;

    private const int LORD_KARAK_MAX_REROLLS = 20;

    #[Column(type: Types::STRING, enumType: GameStatus::class)]
    private GameStatus $status = GameStatus::LOBBY;

    /**
     * @var list<Uuid>
     */
    #[Column(type: UuidArrayJsonType::class)]
    private array $players = [];

    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $currentPlayerId = null;

    #[Column(type: Types::INTEGER)]
    private int $currentTurnNumber = 0;

    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $currentTurnId = null;

    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $leaderboard = [];

    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $winnerId = null;

    /**
     * The dice roll of every player for the starting player (playerId => 2d6); empty in test mode.
     *
     * @var array<string, int>|null
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $startingRolls = null;

    /**
     * Curse of Karak: the player carrying the single curse token.
     */
    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $cursedPlayerId = null;

    /**
     * Curse of Karak: the mummy slayer who still has to choose who gets the token.
     */
    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $curseChoicePendingBy = null;

    /**
     * Curse of Karak: the turn in which the last choice was offered (a re-processed win must not offer it twice).
     */
    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $curseChoiceTurnId = null;

    /**
     * Curse of Karak: the fight that offered the last choice. A re-processed win must not offer it twice,
     * but a second mummy beaten in the same turn (Unstoppable) is a new fight and offers it again.
     */
    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $curseChoiceBattleId = null;

    /**
     * Karak: Regent expansion — the Lord Karak rooms, the new monsters and heroes are in play.
     */
    #[Column(type: BooleanType::class, options: ['default' => false])]
    private bool $regent = false;

    /**
     * Karak: Regent, Lord Karak variant — the weakest hero becomes Lord Karak when the fifth Lord Karak room is placed.
     */
    #[Column(type: BooleanType::class, options: ['default' => false])]
    private bool $lordKarak = false;

    /**
     * Heroes are dealt at random: a player joining without a hero gets one nobody holds; picking a hero is refused.
     * AI players are dealt a hero in every game.
     */
    #[Column(type: BooleanType::class, options: ['default' => false])]
    private bool $randomHeroes = false;

    /**
     * Karak: Regent, Lord Karak variant — the player who became Lord Karak (once per game).
     */
    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $lordKarakPlayerId = null;

    /**
     * Karak: Regent, Lord Karak variant — how Lord Karak was chosen, for the reveal dialog.
     *
     * @var array{playerId: string, turnNumber: int, criterion: string, heroes: list<string>, keepsOwn: bool, standings: list<array{playerId: string, treasure: int, weapons: int, items: int, rolls: list<int>}>}|null
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $lordKarakReveal = null;

    /**
     * Karak: Regent — heroes cursed by a Cursed Room; independent of the mummy token, several at once.
     * (The default matters: the column is NOT NULL and existing games must migrate.).
     *
     * @var list<Uuid>
     */
    #[Column(type: UuidArrayJsonType::class, options: ['default' => '[]'])]
    private array $roomCursedPlayerIds = [];

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $gameId,
    ) {}

    #[Handler]
    public static function create(CreateGame $command, MessageContext $messageContext): self
    {
        if ($command->lordKarak && !$command->regent) {
            throw new \DomainException('The Lord Karak variant needs the Karak: Regent expansion');
        }
        $game = new self(
            gameId: $command->gameId,
        );
        $game->regent = $command->regent;
        $game->lordKarak = $command->lordKarak;
        $game->randomHeroes = $command->randomHeroes;
        $messageContext->dispatch(new GameCreated(
            gameId: $command->gameId,
            gameCreateTime: $command->at,
            deckSize: $command->deckSize,
            regent: $command->regent,
            lordKarak: $command->lordKarak,
            randomHeroes: $command->randomHeroes,
        ));

        return $game;
    }

    /**
     * Lord Karak ranking keys: treasure value (the score), raw strength of every weapon carried (Hanna's slot
     * daggers too, no hero bonus), number of other items (keys and spells).
     *
     * @return array{treasure: int, weapons: int, items: int}
     */
    private static function strength(Player $player): array
    {
        $strength = ['treasure' => 0, 'weapons' => 0, 'items' => 0];
        foreach ($player->getInventory() as $items) {
            foreach ($items as $item) {
                $item = Item::fromAnything($item);
                match ($item->type->getCategory()) {
                    ItemCategory::TREASURE => $strength['treasure'] += $item->treasureValue,
                    ItemCategory::WEAPON => $strength['weapons'] += $item->type->getDamage(),
                    default => ++$strength['items'],
                };
            }
        }

        return $strength;
    }

    /**
     * Lord Karak: the weakest hero — fewest treasures, then the weakest weapons, then the fewest items, then the
     * lowest die (the tied players reroll until one is lowest).
     *
     * @param array<string, array{playerId: string, treasure: int, weapons: int, items: int, rolls: list<int>}> $standings
     * @param callable(): int $rollDie
     *
     * @return array{string, string, array<string, array{playerId: string, treasure: int, weapons: int, items: int, rolls: list<int>}>}
     */
    private static function findWeakest(array $standings, callable $rollDie): array
    {
        $tied = array_keys($standings);
        foreach (['treasure', 'weapons', 'items'] as $key) {
            $lowest = min(array_map(static fn(string $id): int => $standings[$id][$key], $tied));
            $tied = array_values(array_filter($tied, static fn(string $id): bool => $standings[$id][$key] === $lowest));
            if (\count($tied) === 1) {
                return [$tied[0], $key, $standings];
            }
        }

        // Every key tied: roll a die, the tied lowest roll again (bounded: the test dice queue may repeat forever)
        for ($round = 0; $round < self::LORD_KARAK_MAX_REROLLS && \count($tied) > 1; ++$round) {
            $rolls = [];
            foreach ($tied as $id) {
                $roll = $rollDie();
                $standings[$id]['rolls'][] = $roll;
                $rolls[$id] = $roll;
            }
            $lowest = min($rolls);
            $tied = array_values(array_filter($tied, static fn(string $id): bool => $rolls[$id] === $lowest));
        }

        return [$tied[0], 'roll', $standings];
    }

    #[Handler]
    public function getPlayer(GetActivePlayers $message): array
    {
        return $this->getPlayers();
    }

    /**
     * @throws CannotAddPlayerToAlreadyFullGame
     * @throws CannotAddPlayerToAlreadyPreparedGame
     */
    #[Handler]
    public function addPlayer(AddPlayer $command, MessageContext $messageContext): void
    {
        $players = array_values(array_unique(array_merge($this->players, [$command->playerId])));
        if (\count($players) > self::MAX_PLAYERS_COUNT) {
            throw new CannotAddPlayerToAlreadyFullGame();
        }

        if (!$this->status->isPreparing()) {
            throw new CannotAddPlayerToAlreadyPreparedGame();
        }

        // Karak: Regent heroes come with the expansion only
        if ($command->hero?->isRegent() === true && !$this->regent) {
            throw new \DomainException($command->hero->label() . ' needs the Karak: Regent expansion');
        }

        $hero = $command->hero;
        if ($hero === null && ($command->isAi || $this->randomHeroes)) {
            $hero = $this->dealHero($messageContext);
        }
        $this->players = $players;

        // Check TestMode for player HP configuration
        $hp = Player::MAX_HP;
        $testMode = TestMode::getInstance();
        if ($testMode->isEnabled()) {
            $testHp = $testMode->getPlayerHp($this->gameId->toString(), $command->playerId->toString());
            if ($testHp !== null) {
                $hp = $testHp;
            }
        }

        $messageContext->dispatch(new PlayerAdded(
            gameId: $this->gameId,
            playerId: $command->playerId,
            hp: $hp,
            externalId: $command->externalId,
            username: $command->username,
            isAi: $command->isAi,
            hero: $hero,
        ));
    }

    #[Handler]
    public function start(StartGame $command, MessageContext $messageContext): void
    {
        if (!$this->status->isPreparing()) {
            return;
        }

        if ($this->players === []) {
            return;
        }

        $this->status = GameStatus::STARTED;
        $this->currentPlayerId = $this->rollForStartingPlayer();
        $this->currentTurnNumber = 1;

        $messageContext->dispatch(new GameStarted(
            gameId: $this->gameId,
            gameStartTime: $command->at,
        ));

        $messageContext->dispatch(new TurnChanged(
            gameId: $this->gameId,
            currentPlayerId: $this->currentPlayerId,
            turnNumber: $this->currentTurnNumber,
            changedAt: $command->at,
        ));

        // Start the first turn
        $turnId = Uuid::v7();
        $this->currentTurnId = $turnId;
        $messageContext->dispatch(new StartTurn(
            gameId: $this->gameId,
            playerId: $this->currentPlayerId,
            turnNumber: $this->currentTurnNumber,
            turnId: $turnId,
            at: $command->at,
        ));
    }

    #[Handler]
    public function turnEnded(TurnEnded $event, MessageContext $messageContext): void
    {
        // Curse of Karak: a choice that was never made is settled before the next player starts
        if ($this->curseChoicePendingBy !== null) {
            $target = $this->cursedPlayerId !== null ? null : $this->pickCurseTarget($this->curseChoicePendingBy, $messageContext);
            $this->placeCurse($this->curseChoicePendingBy, $target, $messageContext);
        }

        $messageContext->dispatch(new NextTurn($event->gameId));
    }

    /**
     * Curse of Karak: a defeated mummy lets the slayer curse another player.
     * A virtual slayer picks right away (the first other human); a human gets the choice.
     */
    #[Handler]
    public function onMummyDefeated(MummyDefeated $event, MessageContext $messageContext): void
    {
        if (!$this->status->isInProgress() || \count($this->players) < 2) {
            return;
        }
        // A won fight is processed again when it is finalized: the choice is offered once per fight
        // (once per turn when the event does not name the fight)
        if ($event->battleId !== null) {
            if ($this->curseChoiceBattleId?->equals($event->battleId) === true) {
                return;
            }
        } elseif ($this->curseChoiceTurnId?->equals($event->turnId) === true) {
            return;
        }
        $this->curseChoiceTurnId = $event->turnId;
        $this->curseChoiceBattleId = $event->battleId;

        $slayer = $messageContext->dispatch(new GetPlayer($event->playerId));
        if ($slayer->isAi()) {
            $this->placeCurse($event->playerId, $this->pickCurseTarget($event->playerId, $messageContext), $messageContext);

            return;
        }

        $this->curseChoicePendingBy = $event->playerId;
    }

    /**
     * Curse of Karak: the slayer's choice. A null target leaves the token with its current holder.
     *
     * @throws \DomainException
     */
    #[Handler]
    public function cursePlayer(CursePlayer $command, MessageContext $messageContext): void
    {
        if (!$this->status->isInProgress()) {
            throw new \DomainException('Game is not in progress');
        }
        if ($this->currentTurnId === null || !$this->currentTurnId->equals($command->turnId)) {
            throw new \DomainException('Invalid turn ID');
        }
        if ($this->curseChoicePendingBy === null || !$this->curseChoicePendingBy->equals($command->playerId)) {
            throw new \DomainException('No curse to place');
        }

        $target = $command->targetPlayerId;
        if ($target === null) {
            if ($this->cursedPlayerId === null) {
                throw new \DomainException('The curse token is not in play yet, choose a player');
            }
        } else {
            if ($target->equals($command->playerId)) {
                throw new \DomainException('You cannot curse yourself');
            }
            if (!$this->hasPlayer($target)) {
                throw new \DomainException('Unknown player');
            }
        }

        $placed = $this->placeCurse($command->playerId, $target, $messageContext);

        $messageContext->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $this->gameId,
            playerId: $command->playerId,
            action: TurnAction::CURSE,
            additionalData: [
                'target' => $this->cursedPlayerId?->toString(),
                'previous' => $placed['previous'],
                'kept' => $placed['kept'],
                'heroAbility' => null,
            ],
        ));
    }

    /**
     * Curse of Karak: healing at a fountain lifts the curse — the mummy token and a Cursed Room curse alike.
     */
    #[Handler]
    public function onPlayerHealedAtFountain(PlayerHealedAtFountain $event, MessageContext $messageContext): void
    {
        $this->liftCurse($event->playerId, $messageContext);
    }

    /**
     * Karak: Regent, Lord Karak variant — when the fifth Lord Karak room is discovered, the weakest hero becomes
     * Lord Karak: fully healed, the curse removed, the abilities of all other heroes (his own kept with 2 players).
     */
    #[Handler]
    public function onLordKarakRoomDiscovered(LordKarakRoomDiscovered $event, MessageContext $messageContext): void
    {
        if (!$this->lordKarak || $this->lordKarakPlayerId !== null || $event->count < self::LORD_KARAK_ROOMS) {
            return;
        }
        if (!$this->status->isInProgress() || \count($this->players) < 2) {
            return;
        }

        $standings = [];
        $chosen = [];
        foreach ($this->players as $playerId) {
            $player = $messageContext->dispatch(new GetPlayer($playerId));
            $standings[$playerId->toString()] = [
                'playerId' => $playerId->toString(),
                ...self::strength($player),
                'rolls' => [],
            ];
            $chosen[$playerId->toString()] = $player->getChosenHero();
        }

        // Tie-break dice come from the Field, like the battle dice (test games script them there)
        $field = $messageContext->dispatch(new GetField($this->gameId));
        [$weakestId, $criterion, $standings] = self::findWeakest($standings, static fn(): int => $field->getNextDiceRoll(1, 6));
        $weakest = Uuid::fromString($weakestId);

        $keepsOwn = \count($this->players) === 2;
        $heroes = [];
        foreach ($chosen as $playerId => $hero) {
            if ($hero === null || ($playerId === $weakestId && !$keepsOwn) || \in_array($hero, $heroes, true)) {
                continue;
            }
            $heroes[] = $hero;
        }

        $this->lordKarakPlayerId = $weakest;
        $this->lordKarakReveal = [
            'playerId' => $weakestId,
            'turnNumber' => $this->currentTurnNumber,
            'criterion' => $criterion,
            'heroes' => array_map(static fn(HeroType $hero): string => $hero->value, $heroes),
            'keepsOwn' => $keepsOwn,
            'standings' => array_values($standings),
        ];

        $this->liftCurse($weakest, $messageContext);

        $messageContext->dispatch(new LordKarakRevealed(
            gameId: $this->gameId,
            playerId: $weakest,
            heroes: $heroes,
            keepsOwn: $keepsOwn,
            criterion: $criterion,
            turnId: $this->currentTurnId,
        ));

        if ($this->currentTurnId === null || $this->currentPlayerId === null) {
            return;
        }

        try {
            $messageContext->dispatch(new PerformTurnAction(
                turnId: $this->currentTurnId,
                gameId: $this->gameId,
                playerId: $this->currentPlayerId,
                action: TurnAction::LORD_KARAK,
                additionalData: [
                    'player' => $weakestId,
                    'criterion' => $criterion,
                    'heroes' => $this->lordKarakReveal['heroes'],
                    'keepsOwn' => $keepsOwn,
                    'heroAbility' => null,
                ],
            ));
        } catch (\RuntimeException) {
            // The turn may already be over: the transformation still counts
        }
    }

    /**
     * Karak: Regent — a Cursed Room roll of 1–3 curses the hero (several heroes may be cursed this way at once).
     */
    #[Handler]
    public function onCursedRoomEntered(CursedRoomEntered $event, MessageContext $messageContext): void
    {
        if (!$event->cursed || !$this->status->isInProgress()) {
            return;
        }
        if (!$this->isRoomCursed($event->playerId)) {
            $this->roomCursedPlayerIds[] = $event->playerId;
        }

        $messageContext->dispatch(new PlayerCursed(
            gameId: $this->gameId,
            playerId: $event->playerId,
            byPlayerId: $event->playerId,
        ));
    }

    /**
     * Cursed by either source: the mummy token or a Cursed Room.
     */
    public function isPlayerCursed(Uuid $playerId): bool
    {
        return $this->cursedPlayerId?->equals($playerId) === true || $this->isRoomCursed($playerId);
    }

    /**
     * @return list<Uuid>
     */
    public function getRoomCursedPlayerIds(): array
    {
        return $this->roomCursedPlayerIds;
    }

    public function getCursedPlayerId(): ?Uuid
    {
        return $this->cursedPlayerId;
    }

    public function getLordKarakPlayerId(): ?Uuid
    {
        return $this->lordKarakPlayerId;
    }

    /**
     * @return array{playerId: string, turnNumber: int, criterion: string, heroes: list<string>, keepsOwn: bool, standings: list<array{playerId: string, treasure: int, weapons: int, items: int, rolls: list<int>}>}|null
     */
    public function getLordKarakReveal(): ?array
    {
        return $this->lordKarakReveal;
    }

    /**
     * Karak: Regent expansion enabled for this game.
     */
    public function isRegent(): bool
    {
        return $this->regent;
    }

    /**
     * Karak: Regent, Lord Karak variant enabled for this game.
     */
    public function isLordKarak(): bool
    {
        return $this->lordKarak;
    }

    /**
     * Heroes are dealt at random in this game.
     */
    public function isRandomHeroes(): bool
    {
        return $this->randomHeroes;
    }

    /**
     * @return array<string, int>
     */
    public function getStartingRolls(): array
    {
        return $this->startingRolls ?? [];
    }

    public function getCurseChoicePendingBy(): ?Uuid
    {
        return $this->curseChoicePendingBy;
    }

    #[Handler]
    public function nextTurn(NextTurn $command, MessageContext $messageContext): void
    {
        if (!$this->status->isInProgress()) {
            return;
        }

        if ($this->players === []) {
            return;
        }

        // Find the current player's index
        $currentPlayerIndex = -1;
        foreach ($this->players as $index => $playerId) {
            if ($this->currentPlayerId instanceof Uuid && $playerId->equals($this->currentPlayerId)) {
                $currentPlayerIndex = $index;
                break;
            }
        }

        // Determine the next player
        $nextPlayerIndex = ($currentPlayerIndex + 1) % \count($this->players);
        $this->currentPlayerId = $this->players[$nextPlayerIndex];
        ++$this->currentTurnNumber;
        $this->status = GameStatus::TURN_IN_PROGRESS;

        $messageContext->dispatch(new TurnChanged(
            gameId: $this->gameId,
            currentPlayerId: $this->currentPlayerId,
            turnNumber: $this->currentTurnNumber,
            changedAt: $command->at,
        ));

        // Check if the player was stunned (HP = 0) and regenerate HP if needed
        $player = $messageContext->dispatch(new GetPlayer($this->currentPlayerId));
        $isPlayerDefeated = $player->isDefeated();

        if ($isPlayerDefeated) {
            // Player was stunned, regenerate HP
            $messageContext->dispatch(new ResetPlayerHP(
                playerId: $this->currentPlayerId,
                gameId: $this->gameId,
                afterStun: true,
            ));

            // Start the new turn
            $turnId = Uuid::v7();
            $this->currentTurnId = $turnId;
            $messageContext->dispatch(new StartTurn(
                gameId: $this->gameId,
                playerId: $this->currentPlayerId,
                turnNumber: $this->currentTurnNumber,
                turnId: $turnId,
                at: $command->at,
            ));

            // Immediately end the turn since stunned players skip their next turn
            $messageContext->dispatch(new EndTurn(
                turnId: $turnId,
                gameId: $this->gameId,
                playerId: $this->currentPlayerId,
                at: $command->at,
            ));
        } else {
            // Start the new turn normally
            $turnId = Uuid::v7();
            $this->currentTurnId = $turnId;
            $messageContext->dispatch(new StartTurn(
                gameId: $this->gameId,
                playerId: $this->currentPlayerId,
                turnNumber: $this->currentTurnNumber,
                turnId: $turnId,
                at: $command->at,
            ));
        }
    }

    #[Handler]
    public function getCurrentPlayerTurn(GetCurrentPlayer $query): ?Uuid
    {
        return $this->currentPlayerId;
    }

    /**
     * @return Uuid[]
     */
    public function getPlayers(): array
    {
        return $this->players;
    }

    public function getCurrentTurnNumber(): int
    {
        return $this->currentTurnNumber;
    }

    public function getCurrentPlayerId(): ?Uuid
    {
        return $this->currentPlayerId;
    }

    public function getStatus(): GameStatus
    {
        return $this->status;
    }

    public function getCurrentTurnId(): ?Uuid
    {
        return $this->currentTurnId;
    }

    public function getGameId(): Uuid
    {
        return $this->gameId;
    }

    #[Handler]
    public function getCurrentTurn(GetCurrentTurn $query): ?Uuid
    {
        return $this->currentTurnId;
    }

    #[Handler]
    public function get(GetGame $query): self
    {
        return $this;
    }

    #[Handler]
    public function onItemPicked(ItemAddedToInventory $event, MessageContext $messageContext): void
    {
        if (!$event->item->type->endsGame()) {
            return;
        }
        $messageContext->dispatch(new EndGame($event->gameId));
    }

    #[Handler]
    public function endGame(EndGame $event, MessageContext $messageContext): void
    {
        $this->status = GameStatus::FINISHED;

        // Get player UUIDs from this game instance
        $playerIds = $this->getPlayers();

        $scores = [];
        foreach ($playerIds as $playerId) {
            $treasureSum = 0;
            // Get the actual Player object for this ID
            $player = $messageContext->dispatch(new GetPlayer($playerId));
            $inventory = $player->getInventory();
            if (isset($inventory[ItemCategory::TREASURE->value])) {
                foreach ($inventory[ItemCategory::TREASURE->value] as $treasure) {
                    $treasure = Item::fromAnything($treasure);
                    $treasureSum += $treasure->treasureValue;
                }
            }
            $scores[$playerId->toString()] = $treasureSum;
        }
        arsort($scores);
        $this->leaderboard = $scores;

        if (\count($scores) > 0) {
            $this->winnerId = Uuid::fromString(array_key_first($scores));
        }
        $messageContext->dispatch(new GameEnded(
            gameId: $event->gameId,
            winnerId: $this->winnerId,
            scores: $scores,
        ));
    }

    /**
     * Deals a random hero nobody in this game holds yet; the Regent heroes only with the expansion.
     */
    private function dealHero(MessageContext $messageContext): ?HeroType
    {
        $taken = [];
        foreach ($this->players as $playerId) {
            $chosen = $messageContext->dispatch(new GetPlayer($playerId))->getChosenHero();
            if ($chosen !== null) {
                $taken[] = $chosen;
            }
        }

        $pool = array_values(array_filter(
            HeroType::cases(),
            fn(HeroType $hero): bool => ($this->regent || !$hero->isRegent()) && !\in_array($hero, $taken, true),
        ));
        if ($pool === []) {
            error_log(sprintf('WARN Game::dealHero no free hero left in game %s', $this->gameId->toString()));

            return null;
        }

        $key = TestMode::getInstance()->getRandomArrayKey($pool, 'hero');

        return $pool[(int) $key];
    }

    /**
     * Karak: the dice decide who starts (highest 2d6, a tie goes to the first of the tied players in seating order).
     * In test mode the first player starts and no dice are spent, so scripted games stay predictable.
     */
    /**
     * Curse of Karak: lift the curse from a player — the mummy token and a Cursed Room curse alike.
     */
    private function liftCurse(Uuid $playerId, MessageContext $messageContext): void
    {
        if (!$this->isPlayerCursed($playerId)) {
            return;
        }

        if ($this->cursedPlayerId?->equals($playerId) === true) {
            $this->cursedPlayerId = null;
        }
        $this->roomCursedPlayerIds = array_values(array_filter(
            $this->roomCursedPlayerIds,
            static fn(Uuid $id): bool => !$id->equals($playerId),
        ));

        $messageContext->dispatch(new CurseLifted(
            gameId: $this->gameId,
            playerId: $playerId,
        ));
    }

    private function rollForStartingPlayer(): Uuid
    {
        $this->startingRolls = [];
        if (TestMode::getInstance()->isEnabled() || \count($this->players) < 2) {
            return $this->players[0];
        }

        $starter = $this->players[0];
        $best = 0;
        foreach ($this->players as $playerId) {
            $roll = random_int(1, 6) + random_int(1, 6);
            $this->startingRolls[$playerId->toString()] = $roll;
            if ($roll > $best) {
                $best = $roll;
                $starter = $playerId;
            }
        }

        return $starter;
    }

    /**
     * Move the token (or keep it when the target is null) and tell the players involved.
     *
     * @return array{previous: ?string, kept: bool}
     */
    private function placeCurse(Uuid $byPlayerId, ?Uuid $targetPlayerId, MessageContext $messageContext): array
    {
        $previous = $this->cursedPlayerId;
        $this->curseChoicePendingBy = null;

        if ($targetPlayerId === null || ($previous !== null && $previous->equals($targetPlayerId))) {
            return ['previous' => $previous?->toString(), 'kept' => true];
        }

        // The token moves on; a previous holder cursed by a Cursed Room as well stays cursed
        if ($previous !== null && !$this->isRoomCursed($previous)) {
            $messageContext->dispatch(new CurseLifted(
                gameId: $this->gameId,
                playerId: $previous,
            ));
        }

        $this->cursedPlayerId = $targetPlayerId;
        $messageContext->dispatch(new PlayerCursed(
            gameId: $this->gameId,
            playerId: $targetPlayerId,
            byPlayerId: $byPlayerId,
        ));

        return ['previous' => $previous?->toString(), 'kept' => false];
    }

    /**
     * The automatic victim: the first other human player, otherwise the first other player.
     */
    private function pickCurseTarget(Uuid $byPlayerId, MessageContext $messageContext): ?Uuid
    {
        $fallback = null;
        foreach ($this->players as $playerId) {
            if ($playerId->equals($byPlayerId)) {
                continue;
            }
            $fallback ??= $playerId;
            $player = $messageContext->dispatch(new GetPlayer($playerId));
            if (!$player->isAi()) {
                return $playerId;
            }
        }

        return $fallback;
    }

    private function isRoomCursed(Uuid $playerId): bool
    {
        foreach ($this->roomCursedPlayerIds as $cursedId) {
            if ($cursedId->equals($playerId)) {
                return true;
            }
        }

        return false;
    }

    private function hasPlayer(Uuid $playerId): bool
    {
        foreach ($this->players as $knownPlayerId) {
            if ($knownPlayerId->equals($playerId)) {
                return true;
            }
        }

        return false;
    }
}
