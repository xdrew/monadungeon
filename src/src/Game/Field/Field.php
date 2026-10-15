<?php

declare(strict_types=1);

namespace App\Game\Field;

use App\Game\Bag\Bag;
use App\Game\Bag\Error\NoItemsLeftInBag;
use App\Game\Bag\GetBag;
use App\Game\Battle\BattleCompleted;
use App\Game\Battle\BattleFinalized;
use App\Game\Battle\BattleResult;
use App\Game\Battle\MonsterDefeated;
use App\Game\Deck\Error\NoTilesLeftInDeck;
use App\Game\Deck\GetDeck;
use App\Game\Duel\BeginDuel;
use App\Game\Duel\DuelLootTaken;
use App\Game\Duel\DuelPhase;
use App\Game\Duel\DuelResolved;
use App\Game\Duel\DuelSideCompleted;
use App\Game\Duel\DuelStarted;
use App\Game\Duel\SettleDuel;
use App\Game\Duel\StartDuel;
use App\Game\Field\DoctrineDBAL\FieldPlaceArrayJsonType;
use App\Game\Field\DoctrineDBAL\ItemMapType;
use App\Game\Field\DoctrineDBAL\TileOrientationMapType;
use App\Game\Field\DoctrineDBAL\UnplacedTileType;
use App\Game\Field\Error\FieldPlaceIsNotAvailable;
use App\Game\Field\Error\TileCannotBeFound;
use App\Game\Field\Error\TileCannotBePlacedHere;
use App\Game\GameLifecycle\Error\GameAlreadyFinishedException;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GameStarted;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\NextTurn;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemType;
use App\Game\Movement\Commands\MovePlayer;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\DoctrineDBAL\PlayerPositionMapType;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\Events\PlayersSwapped;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Movement\MovementService;
use App\Game\Movement\MovementValidator;
use App\Game\Player\AddItemToInventory;
use App\Game\Player\CompleteReincarnation;
use App\Game\Player\Error\InventoryFullException;
use App\Game\Player\Error\MissingKeyException;
use App\Game\Player\GetActivePlayers;
use App\Game\Player\GetPlayer;
use App\Game\Player\ItemAddedToInventory;
use App\Game\Player\ItemPickedUp;
use App\Game\Player\ItemPlacedOnField;
use App\Game\Player\ItemRemovedFromInventory;
use App\Game\Player\PickItem;
use App\Game\Player\PlayerHealedAtFountain;
use App\Game\Player\PlayerStunned;
use App\Game\Player\QueryPlayerInventory;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\ReincarnationRequired;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Player\ReplaceInventoryItem;
use App\Game\Player\ResetPlayerHP;
use App\Game\Player\UseSpell;
use App\Game\Testing\TestMode;
use App\Game\Turn\EndTurn;
use App\Game\Turn\Error\NotYourTurnException;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnEnded;
use App\Game\Turn\TurnStarted;
use App\Infrastructure\Doctrine\AggregateRoot;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Telephantast\MessageBus\EntityHandler\FindBy;
use Telephantast\MessageBus\EntityHandler\Property;
use Telephantast\MessageBus\Handler\Mapping\Handler;
use Telephantast\MessageBus\MessageBus;
use Telephantast\MessageBus\MessageContext;

#[Entity]
#[Table(schema: 'field')]
#[FindBy(['gameId' => new Property('gameId')])]
class Field extends AggregateRoot
{
    /** @var array<string, Tile> */
    private array $tilesCache = [];

    /** @var array<string, Uuid|string> */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $tiles = [];

    /**
     * Contains tile orientations in character mode.
     * @var array<string, string>
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $tileOrientations = [];

    /**
     * Contains room field places.
     * @var list<FieldPlace>
     */
    #[Column(type: FieldPlaceArrayJsonType::class)]
    private array $roomFieldPlaces = [];

    /**
     * Contains all available for placing tiles field places.
     * @var list<FieldPlace>
     */
    #[Column(type: FieldPlaceArrayJsonType::class)]
    private array $availableFieldPlaces = [];

    /**
     * Contains all available for placing tiles field places and their orientations.
     * @var array<string, TileOrientation>
     */
    #[Column(type: TileOrientationMapType::class, columnDefinition: 'jsonb')]
    private array $availableFieldPlacesOrientation = [];

    /**
     * @var array<string, Item>
     */
    #[Column(type: ItemMapType::class, columnDefinition: 'jsonb')]
    private array $items = [];

    /**
     * @var array<string, FieldPlace>
     * @deprecated Player positions are now tracked in Movement context
     */
    // #[Column(type: PlayerPositionMapType::class, columnDefinition: 'jsonb')]
    // private array $playerPositions = [];

    /**
     * Test mode dice rolls - consumed sequentially during battles.
     * @var list<int>
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $testDiceRolls = null;

    /**
     * @var array<string, string[]>
     * Maps from a field place to available destination field places
     * Structure: [sourceFieldPlace => [destinationFieldPlace1, destinationFieldPlace2, ...]]
     * Where:
     * - sourceFieldPlace is a place with a tile (formatted as "x,y")
     * - destinationFieldPlaceN are connected places (formatted as "x,y")
     * @var array<string, list<string>>
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $transitions = [];

    /**
     * @var array<string, list<FieldPlace>>
     * Maps teleportation gate positions to their connected gates
     */
    private array $teleportationConnections = [];

    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $lastBattleInfo = [];

    /**
     * @var array<string>
     * Tracks item IDs that were consumed in battle and should not be placed on field when removed from inventory
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $consumedItemIds = [];

    /**
     * @var list<FieldPlace>
     * Tracks positions of teleportation gate tiles for quick access
     */
    #[Column(type: FieldPlaceArrayJsonType::class)]
    private array $teleportationGatePositions = [];

    /**
     * @var list<FieldPlace>
     * Tracks positions of healing fountain tiles for quick access
     */
    #[Column(type: FieldPlaceArrayJsonType::class)]
    private array $healingFountainPositions = [];

    /**
     * @var array{tileId: Uuid|null, fieldPlace: FieldPlace|null, orientation: string|null, room: bool|null, features: array|null}|null
     * Stores the tile that has been placed but not yet finalized with movement
     */
    #[Column(type: UnplacedTileType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $unplacedTile = null;

    /**
     * Oracle "Fateweaver": the two tokens drawn for a new room, waiting for the player to choose one.
     *
     * @var array{fieldPlace: string, items: list<array<string, mixed>>}|null
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $pendingFateweaver = null;

    /**
     * Karak: Regent — the last Cursed Room roll, for the UI (cleared when the next turn starts).
     *
     * @var array{playerId: string, position: string, roll: int, cursed: bool, turnId: ?string}|null
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $roomEvent = null;

    /**
     * Karak: Regent — Battle Arenas already activated (each one only once; later it is a normal corridor).
     * (The default matters: the column is NOT NULL and existing fields must migrate.).
     *
     * @var list<FieldPlace>
     */
    #[Column(type: FieldPlaceArrayJsonType::class, options: ['default' => '[]'])]
    private array $activatedArenas = [];

    /**
     * Karak: Regent — a human hero activated an arena and still has to choose a rival (dropped at the turn end).
     *
     * @var array{position: string, playerId: string, turnId: string, firstStep: bool}|null
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $pendingArena = null;

    /**
     * Karak: Regent — the current (or last) Battle Arena duel, for the UI.
     *
     * @var array<string, mixed>|null
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb', nullable: true)]
    private ?array $duelInfo = null;

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $gameId,
    ) {}

    #[Handler]
    public static function create(GameCreated $command, MessageContext $messageContext): self
    {
        $field = new self($command->gameId);

        // Check TestMode for dice rolls configuration and persist them
        $testMode = TestMode::getInstance();
        if ($testMode->isEnabled()) {
            $diceRolls = $testMode->getAllDiceRolls();
            if ($diceRolls !== []) {
                /** @var list<int> $diceRolls */
                $field->testDiceRolls = $diceRolls;
            }
        }

        // Starting tile always has a healing fountain
        $field->healingFountainPositions[] = new FieldPlace(0, 0);

        return $field;
    }

    /**
     * Oracle "Fateweaver": a token without a guard (chest / treasure) beats a monster, then the weaker monster wins.
     */
    private static function pickFateweaverToken(Item $first, Item $second): Item
    {
        $firstGuard = $first->guardDefeated ? 0 : $first->guardHP;
        $secondGuard = $second->guardDefeated ? 0 : $second->guardHP;

        return $secondGuard < $firstGuard ? $second : $first;
    }

    public function getGameId(): Uuid
    {
        return $this->gameId;
    }

    #[Handler]
    public function turnStarted(TurnStarted $turnStarted, MessageContext $messageContext): void
    {
        $this->lastBattleInfo = [];
        $this->roomEvent = null;

        // Get the player's HP
        $player = $messageContext->dispatch(new GetPlayer($turnStarted->playerId));

        // If the player has 0 HP, reset their HP and end their turn
        if ($player->isDefeated()) {
            // Reset the player's HP
            $messageContext->dispatch(new ResetPlayerHP(
                playerId: $turnStarted->playerId,
                gameId: $turnStarted->gameId,
                afterStun: true,
            ));

            // Explicitly end the turn immediately for this stunned player
            // EndTurn will automatically dispatch NextTurn, so we don't need to do it again
            $messageContext->dispatch(new EndTurn(
                turnId: $turnStarted->turnId,
                gameId: $turnStarted->gameId,
                playerId: $turnStarted->playerId,
            ));
        }
    }

    /**
     * Handle player stunned event
     * When a player's HP becomes 0, their turn is ended.
     * Note: Turn ending is now handled by Battle aggregate to ensure proper event ordering.
     */
    #[Handler]
    public function handlePlayerStunned(PlayerStunned $event, MessageContext $messageContext): void
    {
        // Turn ending is handled by Battle aggregate after movement
        // This ensures player is moved back before turn ends
    }

    #[Handler]
    public function onPlayerMoved(PlayerMoved $event, MessageContext $context): void
    {
        // Battle logic has been moved to Battle bounded context
        // Item pickup is a separate action, not automatic on movement
        // Field only handles environmental interactions like healing fountains

        // Check if player stepped on a healing fountain
        // Only heal if this is a battle return movement (player was pushed back after losing)
        if ($event->isBattleReturn && $this->hasHealingFountain($event->toPosition)) {
            $player = $context->dispatch(new GetPlayer($event->playerId));
            // A reincarnating hero is teleported away instead of being healed in place
            if ($player->needsHealing() && !$player->isAwaitingReincarnation()) {
                // Heal player to full HP
                $context->dispatch(new ResetPlayerHP(
                    playerId: $event->playerId,
                    gameId: $event->gameId,
                ));

                // Dispatch healing event
                $context->dispatch(new PlayerHealedAtFountain(
                    gameId: $event->gameId,
                    playerId: $event->playerId,
                    position: $event->toPosition,
                    healedAt: new \DateTimeImmutable(),
                ));

                // Don't record healing as a turn action for battle returns
                // Battle returns are automatic healing, not player actions
            }
        }

        // Turn ending is now handled by:
        // 1. Frontend explicitly calling end-turn API
        // 2. Battle completion (in Battle bounded context)
        // 3. Item pickup actions that have shouldEndTurn() = true
        // 4. GameTurn when MAX_ACTIONS_PER_TURN is reached
        // Regular movement does NOT end turns - players get up to 4 movement actions

        // Karak: Regent — the Lord Karak rooms react to every entry, battle returns included
        $this->enterTile($event->playerId, $event->toPosition, $context);
    }

    /**
     * Warlock "Magic Swap": both heroes entered a new tile.
     */
    #[Handler]
    public function onPlayersSwapped(PlayersSwapped $event, MessageContext $context): void
    {
        $this->enterTile($event->playerId, $event->playerTo, $context);
        $this->enterTile($event->targetPlayerId, $event->playerFrom, $context);
    }

    /**
     * Karak: Regent — the human hero who activated an arena summons a rival: the rival's figure is moved onto
     * the arena and the duel begins with the challenger's roll.
     *
     * @throws \DomainException
     * @throws NotYourTurnException
     */
    #[Handler]
    public function chooseDuelOpponent(ChooseDuelOpponent $command, MessageContext $context): void
    {
        $currentTurnId = $context->dispatch(new GetCurrentTurn($command->gameId));
        if ($currentTurnId === null || !$currentTurnId->equals($command->turnId)) {
            throw new \DomainException('Invalid turn ID');
        }
        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$currentPlayerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }
        $pending = $this->pendingArena;
        if ($pending === null || $pending['playerId'] !== $command->playerId->toString() || $pending['turnId'] !== $command->turnId->toString()) {
            throw new \DomainException('No arena choice pending');
        }
        if ($command->opponentId->equals($command->playerId)) {
            throw new \DomainException('You cannot duel yourself');
        }
        $known = false;
        foreach ($context->dispatch(new GetActivePlayers($command->gameId)) as $playerId) {
            if ($playerId->equals($command->opponentId)) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            throw new \DomainException('Unknown player');
        }

        $this->pendingArena = null;
        $this->startDuel(
            challengerId: $command->playerId,
            opponentId: $command->opponentId,
            arena: FieldPlace::fromString($pending['position']),
            turnId: $command->turnId,
            firstStep: $pending['firstStep'],
            context: $context,
        );
    }

    /**
     * @return list<FieldPlace>
     */
    public function getActivatedArenas(): array
    {
        return $this->activatedArenas;
    }

    /**
     * @return array{position: string, playerId: string, turnId: string, firstStep: bool}|null
     */
    public function getPendingArena(): ?array
    {
        return $this->pendingArena;
    }

    /**
     * Karak: Regent — the pending arena choice for the UI, with the rivals to choose from.
     *
     * @param array<Uuid> $activePlayerIds
     * @return array{position: string, playerId: string, candidates: list<string>}|null
     */
    public function getArenaChoice(array $activePlayerIds): ?array
    {
        if ($this->pendingArena === null) {
            return null;
        }
        $candidates = [];
        foreach ($activePlayerIds as $playerId) {
            if ($playerId->toString() !== $this->pendingArena['playerId']) {
                $candidates[] = $playerId->toString();
            }
        }

        return [
            'position' => $this->pendingArena['position'],
            'playerId' => $this->pendingArena['playerId'],
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDuelInfo(): ?array
    {
        return $this->duelInfo;
    }

    #[Handler]
    public function onDuelStarted(DuelStarted $event): void
    {
        $this->duelInfo = [
            'duelId' => $event->duelId->toString(),
            'phase' => DuelPhase::CHALLENGER_ROLL->value,
            'arena' => $event->arenaPosition->toString(),
            'turnId' => $event->turnId->toString(),
            'challengerId' => $event->challengerId->toString(),
            'opponentId' => $event->opponentId->toString(),
            'challengerBattleId' => $event->challengerBattleId->toString(),
            'opponentBattleId' => $event->opponentBattleId->toString(),
            'challengerTotal' => null,
            'opponentTotal' => null,
            'challengerDetails' => null,
            'opponentDetails' => null,
            'result' => null,
            'loser' => null,
            'reincarnatedTo' => null,
            'loot' => null,
            'lootCandidates' => [],
        ];
    }

    #[Handler]
    public function onDuelSideCompleted(DuelSideCompleted $event): void
    {
        if (!$this->isCurrentDuel($event->duelId)) {
            return;
        }
        /** @var array<string, mixed> $info */
        $info = $this->duelInfo;
        $info[$event->role . 'Total'] = $event->total;
        $info[$event->role . 'Details'] = $event->details();
        // The Duel may already have resolved the whole duel inside this event: the phase only moves forward
        $info['phase'] = $this->laterPhase($info['phase'] ?? null, DuelPhase::OPPONENT_ROLL);
        $this->duelInfo = $info;
    }

    #[Handler]
    public function onDuelLootTaken(DuelLootTaken $event): void
    {
        // The item changes hands: it must not be dropped on the arena when it leaves the loser's inventory
        $this->consumedItemIds[] = $event->item->itemId->toString();

        if (!$this->isCurrentDuel($event->duelId)) {
            return;
        }
        /** @var array<string, mixed> $info */
        $info = $this->duelInfo;
        $info['loot'] = [
            'itemId' => $event->item->itemId->toString(),
            'type' => $event->item->type->value,
            'name' => $event->item->name->value,
        ];
        $info['lootCandidates'] = [];
        $info['phase'] = $this->laterPhase($info['phase'] ?? null, DuelPhase::DONE);
        $this->duelInfo = $info;
    }

    #[Handler]
    public function onDuelResolved(DuelResolved $event): void
    {
        if (!$this->isCurrentDuel($event->duelId)) {
            return;
        }
        /** @var array<string, mixed> $info */
        $info = $this->duelInfo;
        $info['result'] = $event->result;
        $info['loser'] = $event->loserId?->toString();
        $info['reincarnatedTo'] = $event->reincarnatedTo;
        $info['loot'] ??= $event->loot;
        $info['phase'] = $this->laterPhase($info['phase'] ?? null, DuelPhase::from($event->phase));
        $info['lootCandidates'] = $info['phase'] === DuelPhase::LOOT->value ? $event->lootCandidates : [];
        $this->duelInfo = $info;
    }

    /**
     * Karak: Regent — the last Cursed Room roll (null once the next turn started).
     *
     * @return array{playerId: string, position: string, roll: int, cursed: bool, turnId: ?string}|null
     */
    public function getRoomEvent(): ?array
    {
        return $this->roomEvent;
    }

    /**
     * Handle turn started event
     * Check if a stunned player is on a healing fountain and heal them.
     */
    #[Handler]
    public function onTurnStarted(TurnStarted $event, MessageContext $context): void
    {
        // Get player to check if they're stunned (0 HP)
        $player = $context->dispatch(new GetPlayer($event->playerId));

        // If player has 0 HP (stunned), check if they're on a healing fountain
        if ($player->getHP() === 0) {
            try {
                $playerPosition = $context->dispatch(new GetPlayerPosition($event->gameId, $event->playerId));

                // Check if player is on a healing fountain
                if ($this->hasHealingFountain($playerPosition)) {
                    // Heal player to full HP
                    $context->dispatch(new ResetPlayerHP(
                        playerId: $event->playerId,
                        gameId: $event->gameId,
                    ));

                    // Dispatch healing event
                    $context->dispatch(new PlayerHealedAtFountain(
                        gameId: $event->gameId,
                        playerId: $event->playerId,
                        position: $playerPosition,
                        healedAt: new \DateTimeImmutable(),
                    ));

                    // Record healing action
                    $context->dispatch(new PerformTurnAction(
                        turnId: $event->turnId,
                        gameId: $event->gameId,
                        playerId: $event->playerId,
                        action: TurnAction::HEAL_AT_FOUNTAIN,
                        tileId: null,
                        additionalData: [
                            'position' => $playerPosition->toString(),
                            'previousHp' => 0,
                            'healedHp' => $player->getMaxHp(),
                        ],
                        at: new \DateTimeImmutable(),
                    ));
                }
            } catch (\RuntimeException) {
                // If we can't get player position, skip healing check
            }
        }
    }

    /**
     * @throws NoTilesLeftInDeck
     */
    #[Handler]
    public function placeFirstTile(GameStarted $event, MessageContext $messageContext): void
    {
        $startTilePlace = FieldPlace::fromString('0,0');
        $this->availableFieldPlaces = [$startTilePlace];
        $this->transitions = [];
        /** @var list<string> $emptyList */
        $emptyList = [];
        $this->transitions[$startTilePlace->toString()] = $emptyList;

        $startTileId = Uuid::v7();  // Generate a proper UUID for the starting tile
        $deck = $messageContext->dispatch(new GetDeck($event->gameId));
        $deckTile = $deck->getNextTile();
        $startTile = Tile::fromDeckTile($startTileId, $deckTile);

        // Set the available field place orientation based on the actual tile
        $this->availableFieldPlacesOrientation[$startTilePlace->toString()] = $startTile->getOrientation();

        $fieldPlaceString = $startTilePlace->toString();

        $this->tiles[$fieldPlaceString] = $startTileId;
        $this->tileOrientations[$fieldPlaceString] = $startTile->getOrientation()->getCharacter($startTile->room);
        if ($startTile->room) {
            $this->roomFieldPlaces[] = $startTilePlace;
        }
        $this->tilesCache[$startTileId->toString()] = $startTile;

        // Check if starting tile has a teleportation gate (unlikely but possible)
        if (\in_array(TileFeature::TELEPORTATION_GATE, $startTile->getFeatures(), true)) {
            $this->teleportationGatePositions[] = $startTilePlace;
            $this->rebuildTeleportationConnections();
        }

        $messageContext->dispatch(new TilePlaced(
            gameId: $this->gameId,
            tileId: $startTileId,
            fieldPlace: $startTilePlace,
            orientation: $startTile->getOrientation(),
        ));

        // Use the actual tile's orientation for transitions, not a hardcoded fourSide
        $startOrientation = $startTile->getOrientation();
        foreach (array_keys($startOrientation->getOrientation()) as $sideValue) {
            $side = TileSide::from($sideValue);
            if ($startOrientation->isOpenedSide($side)) {
                $siblingFieldPlace = $startTilePlace->getSiblingBySide($side);
                $this->transitions[$startTilePlace->toString()][] = $siblingFieldPlace->toString();
            }
        }
        $this->transitions[$startTilePlace->toString()] = array_values(array_unique($this->transitions[$startTilePlace->toString()]));
        // Player positions are now initialized in Movement context via GameStarted event
        // $game = $messageContext->dispatch(new GetGame($this->gameId));
        // $players = $game->getPlayers();
        // foreach ($players as $player) {
        //     $this->playerPositions[$player->toString()] = $startTilePlace;
        // }
    }

    #[Handler]
    public function getInstance(GetField $query): self
    {
        return $this;
    }

    public function getPlacedTilesAmount(): int
    {
        return \count($this->tiles);
    }

    /**
     * @return array<string, Uuid|string>
     */
    public function getPlacedTiles(): array
    {
        return $this->tiles;
    }

    /**
     * @return FieldPlace[]
     */
    public function getAvailableFieldPlaces(): array
    {
        return $this->availableFieldPlaces;
    }

    public function getRandomAvailablePlace(): ?FieldPlace
    {
        if (\count($this->availableFieldPlaces) === 0) {
            return null;
        }

        return $this->availableFieldPlaces[array_rand($this->availableFieldPlaces)];
    }

    public function getRandomAvailablePlaceForTileOrientation(TileOrientation $orientation): ?FieldPlace
    {
        $availablePlaces = [];
        foreach ($this->availableFieldPlacesOrientation as $place => $availableOrientation) {
            if ($orientation->hasCommonOpenedSides($availableOrientation)) {
                $availablePlaces[] = FieldPlace::fromString($place);
            }
        }
        if ($availablePlaces === []) {
            return null;
        }

        return $availablePlaces[array_rand($availablePlaces)];
    }

    /**
     * @throws TileCannotBeFound
     * @throws TileCannotBePlacedHere
     * @throws FieldPlaceIsNotAvailable
     * @throws NotYourTurnException
     */
    #[Handler]
    public function placeTile(PlaceTile $command, MessageContext $messageContext): void
    {
        // Check if game is finished
        $game = $messageContext->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            throw new GameAlreadyFinishedException();
        }

        // Check if it's the player's turn
        $currentPlayerId = $messageContext->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$command->playerId->equals($currentPlayerId)) {
            throw new NotYourTurnException();
        }

        // Don't store the fieldPlace here - it won't persist if validation fails
        // The unplaced tile should only track that a tile was picked but not placed

        if (!\in_array($command->fieldPlace, $this->availableFieldPlaces, strict: false)) {
            throw new FieldPlaceIsNotAvailable();
        }

        $tile = $this->getTile($command->tileId, $messageContext);

        // Removed the field compatibility check, as per requirements
        // We only need to check if the tile has an opening toward the player

        // Check if the position is reachable from player's current position
        // Get the player's current position from Movement context
        try {
            $playerPosition = $messageContext->dispatch(new GetPlayerPosition($command->gameId, $command->playerId));
        } catch (\RuntimeException) {
            // Player position not found - this can happen during initial setup
            // Skip movement validation and proceed with tile placement
            $playerPosition = null;
        }

        // Only validate movement if we have a player position
        if ($playerPosition !== null) {
            // Check if the target place is directly reachable from player's position
            $isDirectlyReachable = $this->canTransition(FieldPlace::fromAnything($playerPosition), $command->fieldPlace);

            // If not directly reachable, check if it's adjacent with compatible orientation
            $isAdjacentAndConnectable = false;
            if (!$isDirectlyReachable) {
                // Get all adjacent places to the player
                $allAdjacentPlaces = $playerPosition->getAllSiblingsBySides();

                foreach ($allAdjacentPlaces as $side => $adjacentFieldPlace) {
                    if ($adjacentFieldPlace->toString() === $command->fieldPlace->toString()) {
                        // Found the adjacent place that matches our target
                        // Get the player's current tile
                        $playerTile = null;
                        if (isset($this->tiles[$playerPosition->toString()])) {
                            $tileId = $this->tiles[$playerPosition->toString()];
                            // Ensure tileId is a Uuid object (might be string from JSON deserialization)
                            if (\is_string($tileId)) {
                                $tileId = Uuid::fromString($tileId);
                            }
                            $playerTile = $this->getTile($tileId, $messageContext);
                        }

                        if ($playerTile !== null) {
                            // Calculate the side from player to this adjacent place
                            $sideFromPlayer = TileSide::from($side);
                            // Calculate the opposite side (from new tile back to player)
                            $sideFromNewTile = $sideFromPlayer->getOppositeSide();

                            // For a valid connection:
                            // 1. Player's tile MUST have an open side in the direction of the new tile
                            // 2. New tile MUST have an open side in the direction of the player
                            if ($playerTile->getOrientation()->isOpenedSide($sideFromPlayer)
                                && $tile->getOrientation()->isOpenedSide($sideFromNewTile)) {
                                $isAdjacentAndConnectable = true;
                                break;
                            }

                            // This is the crucial check: if player's tile doesn't have an opening
                            // in the direction of the new tile, we can't place it there
                            throw new TileCannotBePlacedHere(
                                "Cannot place tile at {$command->fieldPlace->toString()} " .
                                "because player's current tile doesn't have an opening on the {$sideFromPlayer->name} side " .
                                'to connect to the new tile.',
                            );
                        }
                    }
                }

                // If the place is not adjacently connectable, throw exception
                if (!$isAdjacentAndConnectable) {
                    throw new FieldPlaceIsNotAvailable("Cannot place tile at {$command->fieldPlace->toString()} - not reachable from player's current position at {$playerPosition->toString()}.");
                }
            }
        }

        // Before placing the tile, check if this field place was previously marked as an empty space in transitions
        // and remove those transitions as they will be recalculated
        $fieldPlaceString = $command->fieldPlace->toString();
        foreach ($this->transitions as &$destinations) {
            if (\in_array($fieldPlaceString, $destinations, true)) {
                // Remove the transition to this field place
                $destinations = array_values(array_filter(
                    $destinations,
                    static fn(string $destination) => $destination !== $fieldPlaceString,
                ));
            }
        }

        $this->tiles[$fieldPlaceString] = $command->tileId;
        $this->tileOrientations[$fieldPlaceString] = $tile->getOrientation()->getCharacter($tile->room);
        if ($tile->room) {
            $this->roomFieldPlaces[] = $command->fieldPlace;
        }

        $messageContext->dispatch(new TilePlaced(
            gameId: $command->gameId,
            tileId: $command->tileId,
            fieldPlace: $command->fieldPlace,
            orientation: $tile->getOrientation(),
        ));

        // Record this action for the turn and end the turn
        $messageContext->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: TurnAction::PLACE_TILE,
            tileId: $command->tileId,
            additionalData: ['fieldPlace' => $command->fieldPlace->toString()],
        ));

        // Clear the unplaced tile now that it has been successfully placed
        error_log('Field::placeTile - Clearing unplaced tile after successful placement');
        $this->unplacedTile = null;

        // Move to the next player's turn
        //        $messageContext->dispatch(new NextTurn($command->gameId));
    }

    public function getTileOrientations(): array
    {
        return $this->tileOrientations;
    }

    /**
     * @return FieldPlace[]
     */
    public function getRoomFieldPlaces(): array
    {
        return $this->roomFieldPlaces;
    }

    /**
     * @return array<string, Item>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    #[Handler]
    public function updateAvailableFieldPlaces(TilePlaced $event, MessageContext $messageContext): void
    {
        $fieldPlace = $event->fieldPlace;
        $orientation = $event->orientation;

        // Check if the newly placed tile has special features
        $tile = $this->getTile($event->tileId, $messageContext);
        $features = $tile->getFeatures();

        if (\in_array(TileFeature::TELEPORTATION_GATE, $features, true)) {
            // Add this position to our teleportation gates list
            $this->teleportationGatePositions[] = $fieldPlace;
            $this->rebuildTeleportationConnections();
        }

        if (\in_array(TileFeature::HEALING_FOUNTAIN, $features, true)) {
            // Add this position to our healing fountains list
            $this->healingFountainPositions[] = $fieldPlace;
        }

        foreach (array_keys($event->orientation->getOrientation()) as $sideValue) {
            $side = TileSide::from($sideValue);
            if ($orientation->isOpenedSide($side) === false) {
                continue;
            }
            $siblingFieldPlace = $fieldPlace->getSiblingBySide($side);
            if ($this->isFieldPlaceAlreadyTaken($siblingFieldPlace)) {
                continue;
            }
            $this->addAvailableFieldPlace($siblingFieldPlace, $messageContext);
        }
        $this->removeAvailableFieldPlace($fieldPlace);

        // Update transitions for the newly placed tile
        $this->updateTransitionsForTile($fieldPlace, $orientation);
    }

    /**
     * @throws NoItemsLeftInBag
     */
    #[Handler]
    public function placeItem(TilePlaced $event, MessageContext $messageContext): void
    {
        $tile = $this->getTile($event->tileId, $messageContext);
        if ($tile->room === false) {
            return;
        }
        $bag = $messageContext->dispatch(new GetBag($event->gameId));

        // Oracle "Fateweaver": two tokens are drawn, one is placed, the other goes back into the bag
        $hero = null;
        $isAi = false;

        try {
            $currentPlayerId = $messageContext->dispatch(new GetCurrentPlayer($event->gameId));
            if ($currentPlayerId !== null) {
                $player = $messageContext->dispatch(new GetPlayer($currentPlayerId));
                $hero = $player->getHero();
                $isAi = $player->isAi();
            }
        } catch (\Throwable) {
        }

        if ($hero?->drawsTwoTokens() === true && $bag->count() >= 2) {
            $drawn = [$bag->getNextItem(), $bag->getNextItem()];
            if ($isAi) {
                // A virtual Oracle never waits: it keeps the best token right away
                $this->resolveFateweaver($event->fieldPlace, $drawn, self::pickFateweaverToken($drawn[0], $drawn[1]), $bag);

                return;
            }
            $this->pendingFateweaver = [
                'fieldPlace' => $event->fieldPlace->toString(),
                'items' => [$drawn[0]->toArray(), $drawn[1]->toArray()],
            ];

            return;
        }

        $item = $bag->getNextItem();
        $this->items[$event->fieldPlace->toString()] = $item;
    }

    /**
     * Oracle "Fateweaver": the two drawn tokens waiting for a choice, if any.
     *
     * @return array{fieldPlace: string, items: list<Item>}|null
     */
    public function getPendingFateweaver(): ?array
    {
        if ($this->pendingFateweaver === null) {
            return null;
        }

        return [
            'fieldPlace' => $this->pendingFateweaver['fieldPlace'],
            'items' => array_map(static fn(array $item): Item => Item::fromArray($item), $this->pendingFateweaver['items']),
        ];
    }

    /**
     * Oracle "Fateweaver": place the chosen token on the new room and return the other one to the bag.
     *
     * @throws GameAlreadyFinishedException
     * @throws NotYourTurnException
     * @throws \DomainException
     */
    #[Handler]
    public function chooseFateweaverItem(ChooseFateweaverItem $command, MessageContext $context): void
    {
        $game = $context->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            throw new GameAlreadyFinishedException();
        }

        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$command->playerId->equals($currentPlayerId)) {
            throw new NotYourTurnException();
        }

        $pending = $this->getPendingFateweaver();
        if ($pending === null) {
            throw new \DomainException('No Fateweaver choice is pending');
        }

        $chosen = null;
        $returned = null;
        foreach ($pending['items'] as $item) {
            if ($item->itemId->equals($command->itemId)) {
                $chosen = $item;
            } else {
                $returned = $item;
            }
        }
        if ($chosen === null || $returned === null) {
            throw new \DomainException('Token is not one of the drawn ones');
        }

        $bag = $context->dispatch(new GetBag($command->gameId));
        $this->resolveFateweaver(FieldPlace::fromString($pending['fieldPlace']), $pending['items'], $chosen, $bag);

        $context->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: TurnAction::FATEWEAVER,
            additionalData: [
                'fieldPlace' => $pending['fieldPlace'],
                'chosen' => ['name' => $chosen->name->value, 'type' => $chosen->type->value, 'hp' => $chosen->guardHP],
                'returned' => ['name' => $returned->name->value, 'type' => $returned->type->value, 'hp' => $returned->guardHP],
                'heroAbility' => 'fateweaver',
            ],
        ));
    }

    /**
     * Valduk "Tireless": after a won fight he leaves the loot on the tile and continues his remaining moves.
     * The step into the fight is paid now (a move onto a monster is never recorded as a MOVE).
     *
     * @throws GameAlreadyFinishedException
     * @throws NotYourTurnException
     * @throws \DomainException
     */
    #[Handler]
    public function continueAfterVictory(ContinueAfterVictory $command, MessageContext $context): void
    {
        $game = $context->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            throw new GameAlreadyFinishedException();
        }

        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));
        $currentTurnId = $context->dispatch(new GetCurrentTurn($command->gameId));
        if ($currentPlayerId === null || !$command->playerId->equals($currentPlayerId)
            || $currentTurnId === null || !$command->turnId->equals($currentTurnId)) {
            throw new NotYourTurnException();
        }

        $player = $context->dispatch(new GetPlayer($command->playerId));
        if ($player->getHero()?->tireless() !== true) {
            throw new \DomainException('Only Valduk can keep moving after a victory (Tireless)');
        }

        $position = FieldPlace::fromAnything($context->dispatch(new GetPlayerPosition($command->gameId, $command->playerId)))->toString();
        $battle = $this->lastBattleInfo;
        $reward = $battle['reward'] ?? null;
        $leftItem = isset($this->items[$position]) ? Item::fromAnything($this->items[$position]) : null;
        if (($battle['result'] ?? null) !== BattleResult::WIN->value
            || ($battle['player'] ?? null) !== $command->playerId->toString()
            || ($battle['position'] ?? null) !== $position
            || !\is_array($reward)
            || ($reward['autoCollected'] ?? false) === true
            || $leftItem === null
            || !$leftItem->guardDefeated) {
            throw new \DomainException('No won fight with loot left on this tile');
        }

        $context->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: TurnAction::TIRELESS,
            additionalData: [
                'position' => $position,
                'leftItem' => ['name' => $leftItem->name->value, 'type' => $leftItem->type->value],
                'heroAbility' => 'tireless',
            ],
        ));
        $this->lastBattleInfo['tireless'] = true;
    }

    /**
     * @return list<FieldPlace>
     */
    public function getAllMoveableSiblingsBySides(FieldPlace $fieldPlace, MessageContext $messageContext): array
    {
        $moveableSiblings = [];

        // If no tile at the field place, return empty array
        if (!isset($this->tiles[$fieldPlace->toString()])) {
            return $moveableSiblings;
        }

        // Check if transitions are already calculated
        if (isset($this->transitions[$fieldPlace->toString()])) {
            foreach ($this->transitions[$fieldPlace->toString()] as $destinationPlace) {
                $moveableSiblings[] = FieldPlace::fromString($destinationPlace);
            }
        } else {
            // If transitions aren't calculated yet, check if we need to update them
            $tileId = $this->tiles[$fieldPlace->toString()];
            // Ensure tileId is a Uuid object (might be string from JSON deserialization)
            if (\is_string($tileId)) {
                $tileId = Uuid::fromString($tileId);
            }
            $tile = $this->getTile($tileId, $messageContext);
            $this->updateTransitionsForTile($fieldPlace, $tile->getOrientation());

            // Now check if transitions were added
            if (isset($this->transitions[$fieldPlace->toString()])) {
                foreach ($this->transitions[$fieldPlace->toString()] as $destinationPlace) {
                    $moveableSiblings[] = FieldPlace::fromString($destinationPlace);
                }
            }
        }

        return $moveableSiblings;
    }

    /**
     * Updates a player's position on the field.
     * @deprecated Use Movement context instead
     */
    // public function updatePlayerPosition(Uuid $playerId, FieldPlace $position): void
    // {
    //     $this->playerPositions[$playerId->toString()] = $position;
    // }

    /**
     * Removes items from a specific position on the field.
     */
    public function removeItemsFromPosition(FieldPlace $position): void
    {
        $positionKey = $position->toString();
        if (isset($this->items[$positionKey])) {
            unset($this->items[$positionKey]);
        }
    }

    /**
     * Gets the tile from cache (for MovementService).
     */
    public function getTileFromCache(Uuid $tileId): ?Tile
    {
        return $this->tilesCache[$tileId->toString()] ?? null;
    }

    /**
     * Gets all transitions (for MovementService).
     *
     * @return array<string, list<string>>
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    /**
     * Gets tiles cache (for MovementService).
     *
     * @return array<string, Tile>
     */
    public function getTilesCache(): array
    {
        return $this->tilesCache;
    }

    /**
     * Gets teleportation connections (for MovementService).
     *
     * @return array<string, list<FieldPlace>>
     */
    public function getTeleportationConnections(): array
    {
        return $this->teleportationConnections;
    }

    /**
     * Handles player movement using MovementService for validation.
     * @deprecated Movement logic has been extracted to Movement bounded context
     */
    // #[Handler] // Disabled - handled by Movement context
    /*
    public function movePlayerOld(MovePlayer $command, MessageContext $context): void
    {
        // Get current player
        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));

        // Get player details
        $player = $context->dispatch(new GetPlayer($command->playerId));

        // Get current turn
        $turn = $context->dispatch(new GetTurn($command->turnId));
        if ($turn === null) {
            throw new \RuntimeException('Turn not found');
        }

        // Create movement service with field data
        $movementService = new MovementService(
            $this->transitions,
            $this->tiles,
            $this->tilesCache,
            $this->playerPositions,
            $this->teleportationConnections,
            $this->items,
        );

        // Create validator
        $validator = new MovementValidator();

        // Get player's actual position
        $actualPosition = $this->playerPositions[$command->playerId->toString()] ?? null;

        if (!$actualPosition) {
            throw new \RuntimeException('Player position not found');
        }

        // Check if destination has a monster
        $destinationKey = $command->toPosition->toString();
        $destinationHasMonster = false;
        $monsterItem = null;

        if (isset($this->items[$destinationKey]) && !$command->ignoreMonster) {
            $monsterItem = Item::fromAnything($this->items[$destinationKey]);
            if (!$monsterItem->guardDefeated && $monsterItem->guardHP > 0) {
                $destinationHasMonster = true;
            }
        }

        // Validate movement
        if ($currentPlayerId === null) {
            throw new \RuntimeException('Current player not found');
        }

        $validator->validateMovement(
            $command->playerId,
            $currentPlayerId,
            $command->fromPosition,
            $command->toPosition,
            $actualPosition,
            $turn->hasBattleInTurn(),
            $movementService->canTransition($command->fromPosition, $command->toPosition),
            $player->getHp(),
            $destinationHasMonster,
        );

        // Update player position
        $this->playerPositions[$command->playerId->toString()] = $command->toPosition;

        // Dispatch movement event
        $context->dispatch(new PlayerMoved(
            gameId: $command->gameId,
            playerId: $command->playerId,
            fromPosition: $command->fromPosition,
            toPosition: $command->toPosition,
            movedAt: new \DateTimeImmutable(),
            isBattleReturn: $command->isBattleReturn,
        ));

        // Only record turn action if not a battle return and won't trigger battle
        if (!$command->isBattleReturn && !$destinationHasMonster) {
            $context->dispatch(new PerformTurnAction(
                turnId: $command->turnId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                action: TurnAction::MOVE,
                tileId: null,
                additionalData: [
                    'from' => $command->fromPosition->toString(),
                    'to' => $command->toPosition->toString(),
                ],
            ));
        }

        // Handle post-movement effects
        if (!$command->ignoreMonster && $destinationHasMonster && $monsterItem) {
            // Start battle with monster
            $battleId = Uuid::v7();
            $context->dispatch(new StartBattle(
                battleId: $battleId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                turnId: $command->turnId,
                monster: $monsterItem,
                fromPosition: $command->fromPosition,
                toPosition: $command->toPosition,
            ));
            // Don't pick up items yet - wait for battle to be won
        } elseif (isset($this->items[$destinationKey])) {
            // Only pick up items if there's no monster or monster is already defeated
            $itemAtDestination = $this->items[$destinationKey];

            // Convert to Item object using fromAnything which handles both Item and array
            $itemObj = Item::fromAnything($itemAtDestination);

            // Check if item has an already defeated monster (guardDefeated = true)
            if (!$itemObj->guardHP || $itemObj->guardDefeated) {
                // Items without monsters or with defeated monsters can be picked up
                try {
                    $context->dispatch(new PickItem(
                        gameId: $command->gameId,
                        playerId: $command->playerId,
                        turnId: $command->turnId,
                        position: $command->toPosition,
                        itemIdToReplace: null,
                    ));

                    $context->dispatch(new ItemPickedUp(
                        gameId: $command->gameId,
                        playerId: $command->playerId,
                        item: $itemObj,
                        position: $command->toPosition,
                    ));

                    // Remove items from field after pickup
                    unset($this->items[$destinationKey]);
                } catch (InventoryFullException) {
                    // Inventory is full - leave the item on the field
                    // The player can pick it up later with a replace action
                    // This is not an error, just normal game behavior
                }
            }
        }
    }
    */

    /**
     * Resets a player's position (for testing/demo purposes).
     * @deprecated Movement logic has been extracted to Movement bounded context
     */
    // #[Handler] // Disabled - handled by Movement context
    /*
    public function resetPlayerPositionOld(ResetPlayerPosition $command): void
    {
        $this->playerPositions[$command->playerId->toString()] = $command->position;
    }
    */

    /**
     * Returns all possible transitions from a field place.
     *
     * @return string[] List of destination field place strings
     */
    public function getTransitionsFrom(FieldPlace $fieldPlace): array
    {
        $placeString = $fieldPlace->toString();

        return $this->transitions[$placeString] ?? [];
    }

    /**
     * Returns all field places that can transition to the given field place.
     *
     * @return string[] List of field place strings that can transition to this place
     */
    public function getTransitionsTo(FieldPlace $fieldPlace): array
    {
        $placeString = $fieldPlace->toString();
        $sources = [];

        foreach ($this->transitions as $source => $destinations) {
            if (\in_array($placeString, $destinations, true)) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * Checks if a transition is possible between two field places.
     */
    public function canTransition(FieldPlace $from, FieldPlace $to): bool
    {
        $fromString = $from->toString();
        $toString = $to->toString();

        return isset($this->transitions[$fromString]) && \in_array($toString, $this->transitions[$fromString], true);
    }

    /**
     * Get all possible destinations for a player from their current position.
     *
     * @param Uuid $playerId The ID of the player
     * @param MessageBus $messageBus MessageBus for query context
     * @return list<FieldPlace> List of field places the player can move to
     */
    public function getPossibleDestinationsWithBus(Uuid $playerId, MessageBus $messageBus): array
    {
        try {
            $playerPosition = $messageBus->dispatch(new GetPlayerPosition($this->gameId, $playerId));
        } catch (\RuntimeException) {
            return [];
        }

        // Reuse the logic from getPossibleDestinations
        return $this->calculatePossibleDestinations($playerPosition, $messageBus);
    }

    /**
     * Get all possible destinations for a player from their current position.
     *
     * @param Uuid $playerId The ID of the player
     * @return list<FieldPlace> List of field places the player can move to
     */
    public function getPossibleDestinations(Uuid $playerId, ?MessageContext $messageContext = null): array
    {
        // Get player position from Movement context
        if ($messageContext === null) {
            throw new \RuntimeException('MessageContext is required to query Movement context');
        }

        try {
            $playerPosition = $messageContext->dispatch(new GetPlayerPosition($this->gameId, $playerId));
        } catch (\RuntimeException) {
            return [];
        }

        // Delegate to common logic
        return $this->calculatePossibleDestinationsWithMessageContext($playerPosition, $messageContext);
    }

    /**
     * Get player positions map.
     * @deprecated Use Movement context GetAllPlayerPositions query
     *
     * @return array<string, FieldPlace> Map of player IDs to their positions
     */
    public function getPlayerPositions(): array
    {
        // This method is deprecated - use Movement context instead
        throw new \RuntimeException('Player positions are now tracked in Movement context. Use GetAllPlayerPositions query.');
    }

    /**
     * Returns the transitions map for debugging purposes.
     *
     * @return array<string, array<string>> Map of transitions from source to destinations
     */
    public function getDebugTransitions(): array
    {
        return $this->transitions;
    }

    /**
     * Returns the available field places orientation map.
     *
     * @return array<string, TileOrientation> Map of field places to their available orientations
     */
    public function getAvailableFieldPlacesOrientation(): array
    {
        return $this->availableFieldPlacesOrientation;
    }

    /**
     * Get available places for a player, including places where they can move to and places where they can place a tile.
     */
    #[Handler]
    public function getAvailablePlacesForPlayer(GetAvailablePlacesForPlayer $query): array
    {
        $playerId = $query->playerId;

        // Get player position from Movement context via message bus
        $messageBus = $query->messageBus ?? null;
        if ($messageBus === null) {
            throw new \RuntimeException('MessageBus is required to query Movement context');
        }

        // Oracle "Fateweaver": nothing can be done until the token of the new room is chosen
        if ($this->pendingFateweaver !== null) {
            return [
                'moveTo' => [],
                'placeTile' => [],
            ];
        }

        try {
            $playerPosition = $messageBus->dispatch(new GetPlayerPosition($this->gameId, $playerId));
        } catch (\RuntimeException) {
            $playerPosition = null;
        }

        // Check if player is stunned (HP = 0)
        $hero = null;

        try {
            $player = $messageBus->dispatch(new GetPlayer($playerId));
            if ($player->getHP() <= 0 || $player->isDefeated()) {
                return [
                    'moveTo' => [],
                    'placeTile' => [],
                ];
            }
            $hero = $player->getHero();
        } catch (\Throwable) {
        }

        if (!$playerPosition) {
            return [
                'moveTo' => [],
                'placeTile' => [],
            ];
        }

        $fp = FieldPlace::fromAnything($playerPosition);
        $positionX = $fp->positionX;
        $positionY = $fp->positionY;

        // Get places where player can move to
        $moveToPlaces = $this->getAvailableMovesToPlaces($positionX, $positionY, $playerId, $messageBus);

        // Always get tile placement places - remove deck empty check (doors only, even for the Wizard)
        $placeTilePlaces = $this->getAvailablePlaceTilePlaces($positionX, $positionY, $playerId, $messageBus);

        // Wizard "Astral Walking": every adjacent placed tile is reachable, door or not
        if ($hero?->walksThroughWalls() === true) {
            $knownPlaces = array_map(static fn(FieldPlace $place): string => $place->toString(), $moveToPlaces);
            foreach ($fp->getAllSiblingsBySides() as $sibling) {
                $siblingKey = $sibling->toString();
                if (isset($this->tiles[$siblingKey]) && !\in_array($siblingKey, $knownPlaces, true)) {
                    $moveToPlaces[] = $sibling;
                }
            }
        }

        return [
            'moveTo' => array_map(static fn(FieldPlace $fp) => $fp->toString(), $moveToPlaces),
            'placeTile' => array_map(static fn(FieldPlace $fp) => $fp->toString(), $placeTilePlaces),
        ];
    }

    #[Handler]
    public function onBattleCompleted(BattleCompleted $event, MessageContext $messageContext): void
    {
        // Get player position from Movement context
        try {
            $playerPosition = $messageContext->dispatch(new GetPlayerPosition($event->gameId, $event->playerId));
        } catch (\RuntimeException) {
            // Player not found, nothing to do
            return;
        }

        // Get field place string to look up items
        $positionString = FieldPlace::fromAnything($playerPosition)->toString();

        $this->lastBattleInfo = [
            'battleId' => $event->battleId->toString(),
            'player' => $event->playerId->toString(),
            'position' => $positionString,
            'monster' => $event->monsterHP,
            'diceResults' => $event->diceResults,
            'diceRollDamage' => $event->diceRollDamage,
            'itemDamage' => $event->itemDamage,
            'totalDamage' => $event->totalDamage,
            'usedItems' => $event->usedItems,
            'result' => $event->result->value,
            // New fields for refined battle system
            'availableConsumables' => $event->availableConsumables ?? [],
            'needsConsumableConfirmation' => $event->needsConsumableConfirmation,
            // Hero skills (Warrior "Double Attack")
            'canReroll' => $event->canReroll,
            'rerolled' => $event->rerolled,
            'firstDiceResults' => $event->firstDiceResults,
            // Hero skills (Swordsman "Combat Training": dice showing 1 were rerolled)
            'onesRerolled' => $event->onesRerolled,
            // Hero skills (Thief "Backstab": a tie counts as a victory)
            'tieIsWin' => $event->tieIsWin,
            // Hero skills (Swordsman "Unstoppable": the turn continues after this battle)
            'unstoppable' => $event->unstoppable,
            // Warlock "Sacrifice"
            'canSacrificeHp' => $event->canSacrificeHp,
            'hpSacrificed' => $event->hpSacrificed,
            // Wizard "Magical Affinity"
            'spellsKept' => $event->spellsKept,
            // Oracle "Farseeing"
            'farseeing' => $event->farseeing,
            // Karak: Regent — Valduk "Berserk" bonus, Lady Lorraine "Bear Attack" dice
            'berserk' => $event->berserkBonus,
            'onesAsSixes' => $event->onesAsSixes,
            // Curse of Karak: the slayer of a mummy picks the victim before the turn ends
            'curseChoiceRequired' => $event->curseChoiceRequired,
            'cursedPlayerId' => $event->cursedPlayerId,
            // Karak: Regent — a Battle Arena duel side (the "monster" is the rival hero)
            'duel' => $event->duel,
        ];

        // Karak: Regent — a duel has no monster on the tile: whatever lies on the arena is not part of it
        if ($event->duel !== null) {
            return;
        }

        // Check if there are items at this position
        if (!isset($this->items[$positionString])) {
            return;
        }

        // Get the item and ensure it's an Item object
        $item = Item::fromAnything($this->items[$positionString]);

        // Add monster type information to battle info for better frontend display
        $this->lastBattleInfo['monsterType'] = $item->name->value;

        // Check if consumables would let player win
        if ($event->result !== BattleResult::WIN && $event->needsConsumableConfirmation && $event->availableConsumables !== null && $event->availableConsumables !== []) {
            // Calculate maximum possible damage with consumables
            $consumableDamage = 0;
            foreach ($event->availableConsumables as $consumable) {
                // Fireball +1, Frost Fist +2 (Karak: Regent)
                $consumableDamage += $consumable->type->getDamage();
            }

            // Check if total damage with all consumables would be enough to win
            $maxPotentialDamage = $event->totalDamage + $consumableDamage;
            $potentialVictoryWithConsumables = $maxPotentialDamage > $event->monsterHP;

            // If player could potentially win with consumables, add reward info
            if ($potentialVictoryWithConsumables) {
                // Add reward information to battle info
                $this->lastBattleInfo['reward'] = [
                    'name' => $item->type->value,
                    'type' => $item->type->value,
                    'treasureValue' => $item->treasureValue,
                    'itemId' => $item->itemId->toString(),
                    'autoCollected' => false,
                    'isPotentialReward' => true,
                ];

                // Return here - we've added the reward info but don't want to process
                // the monster as defeated yet
                return;
            }
        }

        // Only process monster defeat if the player won the battle
        if ($event->result !== BattleResult::WIN) {
            return;
        }

        // Only update monster items
        if ($item->guardHP === $event->monsterHP && !$item->guardDefeated) {
            // Create a new item with guardDefeated set to true
            // This ensures Doctrine properly tracks the change
            $defeatedItem = $item->defeatMonster();

            // Check if this is a chest item - if so, automatically collect it
            $isChest = $defeatedItem->type->value === 'chest' || $defeatedItem->type->value === 'ruby_chest';

            if ($isChest) {
                // For chests guarded by monsters, automatically add to player inventory without requiring a key
                // This is different from picking up unguarded chests which require keys
                try {
                    $messageContext->dispatch(new AddItemToInventory(
                        playerId: $event->playerId,
                        gameId: $event->gameId,
                        item: $defeatedItem,
                    ));

                    // Remove the chest from the field since it was automatically collected
                    unset($this->items[$positionString]);

                    // Add reward information to battle info to show it was automatically collected
                    $this->lastBattleInfo['reward'] = [
                        'name' => $defeatedItem->type->value,
                        'type' => $defeatedItem->type->value,
                        'treasureValue' => $defeatedItem->treasureValue,
                        'itemId' => $defeatedItem->itemId->toString(),
                        'autoCollected' => true, // Flag to indicate automatic collection
                    ];
                } catch (\Throwable) {
                    // Update the item in the items array (still mark monster as defeated)
                    // Clone the array to force Doctrine to detect the change
                    $items = $this->items;
                    $items[$positionString] = $defeatedItem;
                    $this->items = $items;

                    // Add reward information to battle info for manual pickup
                    $this->lastBattleInfo['reward'] = [
                        'name' => $defeatedItem->type->value,
                        'type' => $defeatedItem->type->value,
                        'treasureValue' => $defeatedItem->treasureValue,
                        'itemId' => $defeatedItem->itemId->toString(),
                        'autoCollected' => false, // Manual pickup required due to full inventory
                    ];
                }
            } else {
                // For non-chest items (weapons, spells, keys), leave on field for manual pickup
                // Update the item in the items array
                // Clone the array to force Doctrine to detect the change
                $items = $this->items;
                $items[$positionString] = $defeatedItem;
                $this->items = $items;

                // Add reward information to battle info
                $this->lastBattleInfo['reward'] = [
                    'name' => $defeatedItem->type->value,  // The treasure type (sword, key, etc.) - this is the actual reward
                    'type' => $defeatedItem->type->value,  // Keep this for compatibility
                    'treasureValue' => $defeatedItem->treasureValue,
                    'itemId' => $defeatedItem->itemId->toString(),
                    'autoCollected' => false, // Manual pickup required
                ];
            }

            // Dispatch the MonsterDefeated event to notify other components
            // This is used for game progression, achievement tracking, and UI updates
            $messageContext->dispatch(new MonsterDefeated(
                gameId: $event->gameId,
                playerId: $event->playerId,
                position: FieldPlace::fromAnything($playerPosition),
                monster: $defeatedItem,
            ));
        }
    }

    /**
     * Handle battle finalization - track consumed items so they don't get placed on field.
     */
    #[Handler]
    public function onBattleFinalized(BattleFinalized $event): void
    {
        // Track the consumed item IDs so they don't get placed on the field when removed from inventory
        // Wizard "Magical Affinity": kept spells stay in the inventory and may still be dropped on the field later
        $this->consumedItemIds = array_merge(
            $this->consumedItemIds,
            array_values(array_diff($event->selectedConsumableIds, $event->keptConsumableIds)),
        );

        // Do NOT clear the last battle info here - it will be cleared after the final BattleCompleted event
        // This ensures the frontend receives the final battle results after finalization
    }

    public function getLastBattleInfo(): ?array
    {
        return $this->lastBattleInfo === [] ? null : $this->lastBattleInfo;
    }

    /**
     * @throws \Throwable
     */
    #[Handler]
    public function pickItemFromField(PickItem $command, MessageContext $messageContext): ?Item
    {
        // Check if game is already finished
        $game = $messageContext->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            // Game is already finished, don't process item pickup
            return null;
        }

        // Validate it's the player's turn
        $currentTurnId = $messageContext->dispatch(new GetCurrentTurn($command->gameId));
        $currentPlayerId = $messageContext->dispatch(new GetCurrentPlayer($command->gameId));

        if (!$currentTurnId || !$currentTurnId->equals($command->turnId)) {
            throw new \InvalidArgumentException('Invalid turn ID - turn has already ended');
        }

        if (!$currentPlayerId || !$currentPlayerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        // Use the position from the command (where the item actually is)
        // This is important because the player may have moved and the turn changed
        // but we still want to pick up the item from where they moved to
        $positionString = $command->position->toString();

        // Get the player's current position for validation
        try {
            $playerPosition = $messageContext->dispatch(new GetPlayerPosition($command->gameId, $command->playerId));
        } catch (\RuntimeException) {
            throw new \InvalidArgumentException('Player has no position on the field');
        }

        // Check if there's an item at the player's current position
        if (!isset($this->items[$positionString])) {
            // No item found at this position
            return null;
        }

        // Get the item from the field
        $item = Item::fromAnything($this->items[$positionString]);
        $originalItemId = $item->itemId; // Store the original item ID

        // Check if the item has an undefeated monster
        if (!$item->guardDefeated && $item->guardHP > 0) {
            // Check if we just won a battle at this position
            $justWonBattle = false;
            if ($this->lastBattleInfo
                && isset($this->lastBattleInfo['position'])
                && $this->lastBattleInfo['position'] === $positionString
                && isset($this->lastBattleInfo['result'])
                && $this->lastBattleInfo['result'] === 'win'
                && isset($this->lastBattleInfo['player'])
                && $this->lastBattleInfo['player'] === $command->playerId->toString()) {
                $justWonBattle = true;
            }

            if (!$justWonBattle) {
                // Can't pick up items that have undefeated monsters
                throw new \InvalidArgumentException('Cannot pick up item with undefeated monster');
            }

            // Player just won the battle, mark the monster as defeated
            $item = $item->defeatMonster();
        }

        // Check if the item is a chest - if so, the player needs a key
        if ($item->type->value === 'chest') {
            // Query the player's inventory to check for keys
            /** @var array{key: array<Item>, weapon: array<Item>, spell: array<Item>, treasure: array<Item>} $playerInventory */
            $playerInventory = $messageContext->dispatch(new QueryPlayerInventory(
                playerId: $command->playerId,
                gameId: $command->gameId,
            ));

            // Check if there are any keys in the inventory
            if (isset($playerInventory[ItemCategory::KEY->value]) && \count($playerInventory[ItemCategory::KEY->value]) > 0) {
                // Get the key that will be used
                /** @var array<Item> $keys */
                $keys = $playerInventory[ItemCategory::KEY->value];
                $key = Item::fromAnything($keys[0]);

                // Mark the key as consumed so it won't be dropped back to the field
                $this->consumedItemIds[] = $key->itemId->toString();

                // Remove the key from the player's inventory (keys are consumed when opening chests)
                $messageContext->dispatch(new RemoveItemFromInventory(
                    gameId: $command->gameId,
                    playerId: $command->playerId,
                    itemId: $key->itemId,
                ));
            } else {
                // Player doesn't have a key, cannot pick up chest
                throw new MissingKeyException($item);
            }
        }

        // Handle item pickup - either add to inventory or replace an existing item
        try {
            if ($command->itemIdToReplace !== null) {
                // Replace an existing item in the player's inventory
                $messageContext->dispatch(new ReplaceInventoryItem(
                    playerId: $command->playerId,
                    gameId: $command->gameId,
                    itemIdToReplace: $command->itemIdToReplace,
                    newItem: $item,
                ));
            } else {
                // Add the item to the player's inventory normally
                $messageContext->dispatch(new AddItemToInventory(
                    playerId: $command->playerId,
                    gameId: $command->gameId,
                    item: $item,
                ));
            }

            // Remove the original item from the field only if it's still the same item
            // This prevents removing a replaced item that might have been placed there
            if (isset($this->items[$positionString])) {
                $currentItem = Item::fromAnything($this->items[$positionString]);
                if ($currentItem->itemId->equals($originalItemId)) {
                    // Only remove if it's still the original item we picked up
                    unset($this->items[$positionString]);
                }
            }

            // Record this action for the turn
            $additionalData = [
                'position' => $positionString,
                'itemId' => $item->itemId->toString(),
                'monster' => [
                    'type' => $item->type->value,
                    'name' => $item->name->value,
                ],
            ];
            if ($command->itemIdToReplace !== null) {
                $additionalData['replacedItemId'] = $command->itemIdToReplace->toString();
            }

            $messageContext->dispatch(new PerformTurnAction(
                turnId: $command->turnId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                action: TurnAction::PICK_ITEM,
                additionalData: $additionalData,
            ));

            // Dispatch an event that the item was picked up using the actual player position
            $messageContext->dispatch(new ItemPickedUp(
                gameId: $command->gameId,
                playerId: $command->playerId,
                item: $item,
                position: FieldPlace::fromAnything($playerPosition),
            ));

            // End turn logic:
            // - Regular item pickup: do NOT end turn (player can continue their turn)
            // - After battle loss/draw: turn already ended in Battle.php
            // - After battle win: do NOT end turn (player already manually ended turn after battle)
            // Turn ending is handled manually by the player or automatically after certain actions
            // Return the item that was picked up
            return $item;
        } catch (\Throwable $e) {
            // If there's an error (like inventory full), let it propagate up
            throw $e;
        }
    }

    #[Handler]
    public function onItemAddedToInventory(ItemAddedToInventory $event): void
    {
        // This handler is no longer needed as Movement context tracks positions
        // Items are removed from field when picked up in pickItemFromField
        // The pickItemFromField method already handles removing items from the field
    }

    #[Handler]
    public function onItemRemovedFromInventory(ItemRemovedFromInventory $event, MessageContext $messageContext): void
    {
        // Check if this item was consumed in battle - if so, don't place it on the field
        $itemIdString = $event->item->itemId->toString();
        if (\in_array($itemIdString, $this->consumedItemIds, true)) {
            // Remove the item from consumed list (cleanup) and return without placing on field
            $this->consumedItemIds = array_values(array_filter(
                $this->consumedItemIds,
                static fn(string $consumedId) => $consumedId !== $itemIdString,
            ));

            return;
        }

        // Find player's current position from Movement context
        try {
            $playerPosition = $messageContext->dispatch(new GetPlayerPosition($event->gameId, $event->playerId));
        } catch (\RuntimeException) {
            return; // Player has no position
        }

        $positionString = $playerPosition->toString();

        // Check if there's already an item at the player's position
        //        if (isset($this->items[$positionString])) {
        //            // If there's already an item, we don't place another one
        //            // In a real game, we might want to create a "stack" of items or choose one
        //            return;
        //        }

        try {
            // Get the item details from the bag (or create a default if not found)
            $item = $event->item;

            // Place the item at the player's current position
            $this->items[$positionString] = $item;

            // Log that item was placed
            $messageContext->dispatch(new ItemPlacedOnField(
                gameId: $event->gameId,
                itemId: $item->itemId,
                fieldPlace: $playerPosition,
            ));
        } catch (\Throwable) {
            // Handle case where item cannot be found or created
            // This might happen in rare edge cases
        }
    }

    /**
     * Set test dice rolls for predictable testing.
     * @param list<int> $diceRolls
     */
    public function setTestDiceRolls(array $diceRolls): void
    {
        $this->testDiceRolls = $diceRolls;
    }

    /**
     * Get next dice roll - either from test rolls or random.
     */
    public function getNextDiceRoll(int $min = 1, int $max = 6): int
    {
        // First check if we have persisted test dice rolls
        if ($this->testDiceRolls !== null && \count($this->testDiceRolls) > 0) {
            // Pop the first dice roll from the array
            $roll = array_shift($this->testDiceRolls);

            // If the array is now empty, set it to null to switch back to random
            if (\count($this->testDiceRolls) === 0) {
                $this->testDiceRolls = null;
            }

            return $roll;
        }

        // Otherwise use random
        return random_int($min, $max);
    }

    /**
     * Get tile features for all placed tiles.
     * @return array<string, array<TileFeature>> Map of position => features
     */
    public function getTileFeatures(MessageBus $messageBus): array
    {
        $tileFeatures = [];

        // Special handling for starting tile which always has healing fountain
        if (isset($this->tiles['0,0'])) {
            $tileFeatures['0,0'] = [TileFeature::HEALING_FOUNTAIN];
        }

        foreach ($this->tiles as $position => $tileId) {
            // Skip starting tile as we've already handled it
            if ($position === '0,0') {
                continue;
            }

            try {
                // Convert string to UUID if necessary
                if (\is_string($tileId)) {
                    $tileId = Uuid::fromString($tileId);
                }

                // First check if tile is in cache
                if (isset($this->tilesCache[$tileId->toString()])) {
                    $tile = $this->tilesCache[$tileId->toString()];
                } else {
                    // If not in cache, try to load from database
                    try {
                        $tile = $messageBus->dispatch(new GetTile($tileId));
                        $this->tilesCache[$tileId->toString()] = $tile;
                    } catch (\Throwable $e) {
                        // Log the error for debugging
                        error_log("Failed to load tile {$tileId} at position {$position}: " . $e->getMessage());

                        // Tile might not exist in DB yet
                        // Check if this is a corner room tile that might have healing fountain
                        if (isset($this->tileOrientations[$position])
                            && $this->tileOrientations[$position] === '╬'
                            && \in_array(FieldPlace::fromString($position), $this->roomFieldPlaces, true)) {
                            // This is a corner room tile, it might have a healing fountain
                            // We'll need to check the deck to know for sure, but for now skip
                        }

                        continue;
                    }
                }

                $features = $tile->getFeatures();
                if ($features !== []) {
                    $tileFeatures[$position] = $features;
                }
            } catch (\Throwable $e) {
                // Unable to get tile, log and skip
                error_log("Error processing tile at position {$position}: " . $e->getMessage());
            }
        }

        return $tileFeatures;
    }

    /**
     * Check if player is on a healing fountain at the end of their turn and heal them.
     */
    #[Handler]
    public function onTurnEnded(TurnEnded $event, MessageContext $messageContext): void
    {
        // Karak: Regent — a rival never chosen means no duel (the arena stays activated); an open duel is settled
        if ($this->pendingArena !== null && $this->pendingArena['turnId'] === $event->turnId->toString()) {
            $this->pendingArena = null;
        }
        $duelPhase = DuelPhase::tryFrom((string) ($this->duelInfo['phase'] ?? ''));
        if ($duelPhase !== null && !$duelPhase->isSettled() && \is_string($this->duelInfo['duelId'] ?? null)) {
            $messageContext->dispatch(new SettleDuel(
                duelId: Uuid::fromString($this->duelInfo['duelId']),
                gameId: $event->gameId,
            ));
        }

        // Oracle "Fateweaver": a choice that was never made is settled with the first drawn token
        $pending = $this->getPendingFateweaver();
        if ($pending !== null) {
            $bag = $messageContext->dispatch(new GetBag($event->gameId));
            $this->resolveFateweaver(FieldPlace::fromString($pending['fieldPlace']), $pending['items'], $pending['items'][0], $bag);
        }

        // Get the player's current position from Movement context
        try {
            $playerPosition = $messageContext->dispatch(new GetPlayerPosition($event->gameId, $event->playerId));
        } catch (\RuntimeException) {
            return; // Player has no position
        }

        // Special handling for starting position (0,0) which always has healing fountain
        if ($playerPosition->toString() === '0,0') {
            // Dispatch healing command
            $messageContext->dispatch(new ResetPlayerHP(
                playerId: $event->playerId,
                gameId: $event->gameId,
                afterStun: false,
            ));

            // Dispatch healing event
            $messageContext->dispatch(new PlayerHealedAtFountain(
                playerId: $event->playerId,
                gameId: $event->gameId,
                position: $playerPosition,
                healedAt: new \DateTimeImmutable(),
            ));

            return;
        }

        // Check if there's a tile at the player's position
        if (!isset($this->tiles[$playerPosition->toString()])) {
            return;
        }

        $tileId = $this->tiles[$playerPosition->toString()];

        // Ensure tileId is a Uuid object (might be string from JSON deserialization)
        if (\is_string($tileId)) {
            $tileId = Uuid::fromString($tileId);
        }

        // Get the tile from cache or database
        $tileIdString = $tileId->toString();
        if (isset($this->tilesCache[$tileIdString])) {
            $tile = $this->tilesCache[$tileIdString];
        } else {
            try {
                $tile = $messageContext->dispatch(new GetTile($tileId));
                $this->tilesCache[$tileIdString] = $tile;
            } catch (\Throwable) {
                // Tile might not exist in DB yet
                return;
            }
        }

        // Check if the tile has a healing fountain
        if (\in_array(TileFeature::HEALING_FOUNTAIN, $tile->getFeatures(), true)) {
            // Dispatch healing command
            $messageContext->dispatch(new ResetPlayerHP(
                playerId: $event->playerId,
                gameId: $event->gameId,
                afterStun: false,
            ));

            // Dispatch healing event
            $messageContext->dispatch(new PlayerHealedAtFountain(
                playerId: $event->playerId,
                gameId: $event->gameId,
                position: $playerPosition,
                healedAt: new \DateTimeImmutable(),
            ));
        }
    }

    /**
     * Get all healing fountain positions on the field.
     * @return list<FieldPlace>
     */
    public function getHealingFountainPositions(): array
    {
        return $this->healingFountainPositions;
    }

    /**
     * Check if a position has a healing fountain.
     */
    public function hasHealingFountain(FieldPlace $position): bool
    {
        foreach ($this->healingFountainPositions as $fountainPos) {
            if ($fountainPos->equals($position)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the unplaced tile information (tile that has been placed but player hasn't moved yet).
     * @return array{tileId: Uuid|null, fieldPlace: FieldPlace|null}|null
     */
    public function getUnplacedTile(): ?array
    {
        return $this->unplacedTile;
    }

    /**
     * Handle when a tile is picked from deck - store it as pending placement.
     */
    #[Handler]
    public function onTilePicked(TilePicked $event): void
    {
        // Store that a tile has been picked and where it will be placed, including tile details
        $this->unplacedTile = [
            'tileId' => $event->tileId,
            'fieldPlace' => $event->fieldPlace,
            'orientation' => $event->orientation->toString(),
            'room' => $event->room,
            'features' => array_map(static fn($f) => $f->value, $event->features),
        ];
    }

    /**
     * Clear the unplaced tile when picking a different tile.
     */
    #[Handler]
    public function clearUnplacedTile(ClearUnplacedTile $command): void
    {
        $this->unplacedTile = null;
    }

    /**
     * Update the orientation of the unplaced tile when it's rotated.
     */
    #[Handler]
    public function onTileRotated(TileRotated $event): void
    {
        if ($this->unplacedTile !== null
            && $this->unplacedTile['tileId'] !== null
            && $this->unplacedTile['tileId']->equals($event->tileId)) {
            $this->unplacedTile['orientation'] = $event->orientation->toString();
        }
    }

    #[Handler]
    public function useSpell(UseSpell $command, MessageContext $context): void
    {
        // Validate it's the player's turn
        $currentTurnId = $context->dispatch(new GetCurrentTurn($command->gameId));
        if ($currentTurnId === null || !$currentTurnId->equals($command->turnId)) {
            throw new \InvalidArgumentException('Invalid turn ID');
        }

        // Get current player ID
        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$currentPlayerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        // Get player inventory to validate spell ownership
        $inventory = $context->dispatch(new QueryPlayerInventory(
            playerId: $command->playerId,
            gameId: $command->gameId,
        ));

        $spell = null;
        // Check in the spells category (note: backend uses 'spell' not 'spells')
        $spells = $inventory['spell'] ?? [];

        foreach ($spells as $item) {
            // Convert item to Item object if it's an array
            $itemObj = Item::fromAnything($item);
            if ($itemObj->itemId->equals($command->spellId)) {
                $spell = $itemObj;
                break;
            }
        }

        if ($spell === null) {
            throw new \InvalidArgumentException('Spell not found in inventory');
        }

        if ($spell->type->getCategory() !== ItemCategory::SPELL) {
            throw new \InvalidArgumentException('Item is not a spell');
        }

        // Handle teleport spell
        if ($spell->type === ItemType::TELEPORT) {
            if ($command->targetPosition === null) {
                throw new \InvalidArgumentException('Target position required for teleport spell');
            }

            // Get all healing fountain positions
            $healingFountainPositions = $this->getHealingFountainPositions();

            // Validate target is a healing fountain
            $targetIsHealingFountain = false;
            foreach ($healingFountainPositions as $fountainPos) {
                if ($fountainPos->equals($command->targetPosition)) {
                    $targetIsHealingFountain = true;
                    break;
                }
            }

            if (!$targetIsHealingFountain) {
                throw new \InvalidArgumentException('Teleport target must be a healing fountain');
            }

            // Portal of Healing: the caster or any other player of this game is carried to the fountain
            $target = $command->targetPlayerId ?? $command->playerId;
            $game = $context->dispatch(new GetGame($command->gameId));
            $targetKnown = false;
            foreach ($game->getPlayers() as $knownPlayerId) {
                if ($knownPlayerId->equals($target)) {
                    $targetKnown = true;
                    break;
                }
            }
            if (!$targetKnown) {
                throw new \InvalidArgumentException('Target player is not in this game');
            }

            // Mark the spell as consumed so it won't be dropped on the field
            $this->consumedItemIds[] = $command->spellId->toString();

            // Move the target via Movement context and heal them right away (this also lifts the Curse of Karak)
            $context->dispatch(new ResetPlayerPosition(
                gameId: $command->gameId,
                playerId: $target,
                position: $command->targetPosition,
            ));
            $context->dispatch(new ResetPlayerHP(
                playerId: $target,
                gameId: $command->gameId,
                afterStun: false,
            ));
            $context->dispatch(new PlayerHealedAtFountain(
                playerId: $target,
                gameId: $command->gameId,
                position: $command->targetPosition,
                healedAt: new \DateTimeImmutable(),
            ));

            // Remove spell from the caster's inventory
            $context->dispatch(new RemoveItemFromInventory(
                gameId: $command->gameId,
                playerId: $command->playerId,
                itemId: $command->spellId,
            ));

            // The portal costs no move and does not end the turn: the caster keeps the remaining moves
            $context->dispatch(new PerformTurnAction(
                turnId: $command->turnId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                action: TurnAction::USE_SPELL,
                additionalData: [
                    'spell' => 'teleport',
                    'target' => $target->toString(),
                    'self' => $target->equals($command->playerId),
                    'position' => $command->targetPosition->toString(),
                ],
            ));

            return;
        }

        // Karak: Regent — Dark Thorn: another hero loses 1 HP; costs no move, several per turn, even on the same hero
        if ($spell->type === ItemType::DARK_THORN) {
            $target = $command->targetPlayerId;
            if ($target === null) {
                throw new \InvalidArgumentException('Dark Thorn needs a target player');
            }
            if ($target->equals($command->playerId)) {
                throw new \InvalidArgumentException('You cannot target yourself');
            }
            $game = $context->dispatch(new GetGame($command->gameId));
            $targetKnown = false;
            foreach ($game->getPlayers() as $knownPlayerId) {
                if ($knownPlayerId->equals($target)) {
                    $targetKnown = true;
                    break;
                }
            }
            if (!$targetKnown) {
                throw new \InvalidArgumentException('Target player is not in this game');
            }

            $hpBefore = $context->dispatch(new GetPlayer($target))->getHP();

            // Mark the spell as consumed so it won't be dropped on the field
            $this->consumedItemIds[] = $command->spellId->toString();

            $context->dispatch(new ReducePlayerHP(
                playerId: $target,
                gameId: $command->gameId,
                amount: 1,
                turnId: $command->turnId,
            ));

            // A Warrior losing his last HP out of turn reincarnates at the nearest fountain right away
            $targetPlayer = $context->dispatch(new GetPlayer($target));
            $reincarnatedTo = null;
            if ($targetPlayer->isAwaitingReincarnation()) {
                $this->autoReincarnate(new AutoReincarnate(gameId: $command->gameId, playerId: $target), $context);
                $reincarnatedTo = $context->dispatch(new GetPlayerPosition(gameId: $command->gameId, playerId: $target))->toString();
                $targetPlayer = $context->dispatch(new GetPlayer($target));
            }
            $hpAfter = $targetPlayer->getHP();

            $context->dispatch(new RemoveItemFromInventory(
                gameId: $command->gameId,
                playerId: $command->playerId,
                itemId: $command->spellId,
            ));

            $context->dispatch(new PerformTurnAction(
                turnId: $command->turnId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                action: TurnAction::USE_SPELL,
                additionalData: [
                    'spell' => 'dark_thorn',
                    'target' => $target->toString(),
                    'hpBefore' => $hpBefore,
                    'hpAfter' => $hpAfter,
                    'stunned' => $hpAfter === 0 && $reincarnatedTo === null,
                    'reincarnatedTo' => $reincarnatedTo,
                ],
            ));

            return;
        }

        // Karak: Regent — Frost Fist is a battle consumable: it is selected in the fight, not cast from the sidebar
        if ($spell->type === ItemType::FROST_FIST) {
            throw new \InvalidArgumentException('Frost Fist is used during a fight');
        }

        throw new \InvalidArgumentException('This spell cannot be cast');
    }

    /**
     * Warrior "Reincarnation" without a choice: the hero knocked out outside his own fight (a Dark Thorn target,
     * a duel loser) is carried to the discovered healing fountain nearest to him (Manhattan distance, ties → the
     * first discovered). No turn action is recorded: the caller's entry carries `reincarnatedTo`.
     */
    #[Handler]
    public function autoReincarnate(AutoReincarnate $command, MessageContext $context): void
    {
        $player = $context->dispatch(new GetPlayer($command->playerId));
        if (!$player->isAwaitingReincarnation()) {
            return;
        }

        $from = $context->dispatch(new GetPlayerPosition(gameId: $command->gameId, playerId: $command->playerId));
        $target = null;
        $best = null;
        foreach ($this->healingFountainPositions as $fountain) {
            $distance = abs($fountain->positionX - $from->positionX) + abs($fountain->positionY - $from->positionY);
            if ($best === null || $distance < $best) {
                $best = $distance;
                $target = $fountain;
            }
        }
        $target ??= new FieldPlace(0, 0);

        $context->dispatch(new ResetPlayerPosition(
            gameId: $command->gameId,
            playerId: $command->playerId,
            position: $target,
        ));
        $context->dispatch(new CompleteReincarnation(
            gameId: $command->gameId,
            playerId: $command->playerId,
            position: $target,
        ));

        unset($this->lastBattleInfo['awaitingReincarnation'], $this->lastBattleInfo['healingFountainPositions']);
    }

    /**
     * Warrior "Reincarnation": the fallen hero must choose a healing fountain; expose it in the last battle info.
     */
    #[Handler]
    public function onReincarnationRequired(ReincarnationRequired $event): void
    {
        $this->lastBattleInfo['awaitingReincarnation'] = true;
        $this->lastBattleInfo['healingFountainPositions'] = array_values(array_unique(array_map(
            static fn(FieldPlace $place): string => $place->toString(),
            $this->healingFountainPositions,
        )));
    }

    /**
     * Warrior "Reincarnation": teleport to the chosen healing fountain, replenish HP and end the turn.
     *
     * @throws \InvalidArgumentException
     * @throws NotYourTurnException
     * @throws \DomainException
     */
    #[Handler]
    public function reincarnate(Reincarnate $command, MessageContext $context): void
    {
        $currentTurnId = $context->dispatch(new GetCurrentTurn($command->gameId));
        if ($currentTurnId === null || !$currentTurnId->equals($command->turnId)) {
            throw new \InvalidArgumentException('Invalid turn ID');
        }

        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$currentPlayerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        $player = $context->dispatch(new GetPlayer($command->playerId));
        if (!$player->isAwaitingReincarnation()) {
            throw new \DomainException('Player is not awaiting reincarnation');
        }

        if (!$this->hasHealingFountain($command->targetPosition)) {
            throw new \InvalidArgumentException('Reincarnation target must be a healing fountain');
        }

        // Teleport the hero to the chosen fountain
        $context->dispatch(new ResetPlayerPosition(
            gameId: $command->gameId,
            playerId: $command->playerId,
            position: $command->targetPosition,
        ));

        // Replenish all HP
        $context->dispatch(new CompleteReincarnation(
            gameId: $command->gameId,
            playerId: $command->playerId,
            position: $command->targetPosition,
        ));

        // Record the hero ability in the turn log
        $context->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: TurnAction::USE_HERO_ABILITY,
            tileId: null,
            additionalData: [
                'ability' => 'reincarnation',
                'position' => $command->targetPosition->toString(),
            ],
            at: new \DateTimeImmutable(),
        ));

        unset($this->lastBattleInfo['awaitingReincarnation'], $this->lastBattleInfo['healingFountainPositions']);

        // The turn ends anyway
        $context->dispatch(new EndTurn(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
        ));
    }

    protected function getTile(Uuid $tileId, MessageContext $messageContext): Tile
    {
        if ($cachedTile = $this->tilesCache[$tileId->toString()] ?? null) {
            return $cachedTile;
        }

        $tile = $messageContext->dispatch(new GetTile($tileId));
        if (!$tile instanceof Tile) {
            throw new \RuntimeException(sprintf('Tile with ID %s not found', $tileId->toString()));
        }
        $this->tilesCache[$tileId->toString()] = $tile;

        return $tile;
    }

    /**
     * Karak: Regent — a hero stands on a new tile: a Cursed Room makes him roll, a Battle Arena may summon a rival.
     */
    private function enterTile(Uuid $playerId, FieldPlace $position, MessageContext $context): void
    {
        $tile = $this->tileAt($position, $context);
        if ($tile === null) {
            return;
        }

        if ($tile->hasFeature(TileFeature::CURSED_ROOM)) {
            $this->rollCursedRoom($playerId, $position, $context);
        }

        if ($tile->hasFeature(TileFeature::BATTLE_ARENA)) {
            $this->enterArena($playerId, $position, $context);
        }
    }

    /**
     * Karak: Regent — Cursed Room: 1–3 → the Curse of Karak, 4–6 → avoided; an already cursed hero does not roll.
     */
    private function rollCursedRoom(Uuid $playerId, FieldPlace $position, MessageContext $context): void
    {
        $player = $context->dispatch(new GetPlayer($playerId));
        if ($player->isCursed()) {
            return;
        }

        $roll = $this->getNextDiceRoll(1, 6);
        $cursed = $roll <= 3;
        $turnId = $context->dispatch(new GetCurrentTurn($this->gameId));

        $this->roomEvent = [
            'playerId' => $playerId->toString(),
            'position' => $position->toString(),
            'roll' => $roll,
            'cursed' => $cursed,
            'turnId' => $turnId?->toString(),
        ];

        $context->dispatch(new CursedRoomEntered(
            gameId: $this->gameId,
            playerId: $playerId,
            position: $position,
            roll: $roll,
            cursed: $cursed,
            turnId: $turnId,
        ));

        // Record the roll on the current turn (the mover may be another hero swapped onto the room)
        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($this->gameId));
        if ($turnId === null || $currentPlayerId === null) {
            return;
        }

        try {
            $context->dispatch(new PerformTurnAction(
                turnId: $turnId,
                gameId: $this->gameId,
                playerId: $currentPlayerId,
                action: TurnAction::CURSED_ROOM,
                additionalData: [
                    'player' => $playerId->toString(),
                    'position' => $position->toString(),
                    'roll' => $roll,
                    'cursed' => $cursed,
                    'heroAbility' => null,
                ],
            ));
        } catch (\RuntimeException) {
            // The turn may already be over (a battle return at the end of the turn): the roll still counts
        }
    }

    /**
     * Karak: Regent — the first entry of the current player activates a Battle Arena (once, even when no duel
     * follows). A virtual player summons the richest rival at once; a human chooses (ChooseDuelOpponent).
     */
    private function enterArena(Uuid $playerId, FieldPlace $position, MessageContext $context): void
    {
        foreach ($this->activatedArenas as $arena) {
            if ($arena->equals($position)) {
                return;
            }
        }

        // A hero swapped onto the arena out of his turn does not activate it
        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($this->gameId));
        if ($currentPlayerId === null || !$currentPlayerId->equals($playerId)) {
            return;
        }
        $turnId = $context->dispatch(new GetCurrentTurn($this->gameId));
        if ($turnId === null) {
            return;
        }

        $this->activatedArenas[] = $position;

        $others = array_values(array_filter(
            $context->dispatch(new GetActivePlayers($this->gameId)),
            static fn(Uuid $id): bool => !$id->equals($playerId),
        ));
        if ($others === []) {
            return;
        }

        // The MOVE is recorded after PlayerMoved: no move spent yet means the arena is the first step
        $firstStep = $context->dispatch(new GetTurn($turnId))?->getPerformedActionsCount() === 0;

        if ($context->dispatch(new GetPlayer($playerId))->isAi()) {
            $this->startDuel($playerId, $this->pickDuelOpponent($others, $context), $position, $turnId, $firstStep, $context);

            return;
        }

        $this->pendingArena = [
            'position' => $position->toString(),
            'playerId' => $playerId->toString(),
            'turnId' => $turnId->toString(),
            'firstStep' => $firstStep,
        ];
        $context->dispatch(new ArenaEntered(gameId: $this->gameId, playerId: $playerId, position: $position));
    }

    /**
     * A virtual challenger picks the rival with the most treasures (tie → the first in seating order).
     *
     * @param non-empty-list<Uuid> $candidates
     */
    private function pickDuelOpponent(array $candidates, MessageContext $context): Uuid
    {
        $best = $candidates[0];
        $bestCount = -1;
        foreach ($candidates as $candidate) {
            $count = \count($context->dispatch(new GetPlayer($candidate))->getInventory()[ItemCategory::TREASURE->value] ?? []);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * The rival's figure is moved onto the arena; the duel is created first and then begun, so the Duel
     * aggregate exists when an automatic side completes right away.
     */
    private function startDuel(Uuid $challengerId, Uuid $opponentId, FieldPlace $arena, Uuid $turnId, bool $firstStep, MessageContext $context): void
    {
        $context->dispatch(new ResetPlayerPosition(
            gameId: $this->gameId,
            playerId: $opponentId,
            position: $arena,
        ));

        $duelId = Uuid::v7();
        $context->dispatch(new StartDuel(
            duelId: $duelId,
            gameId: $this->gameId,
            turnId: $turnId,
            arenaPosition: $arena,
            challengerId: $challengerId,
            opponentId: $opponentId,
            firstStep: $firstStep,
        ));
        $context->dispatch(new BeginDuel(duelId: $duelId, gameId: $this->gameId));
    }

    private function isCurrentDuel(Uuid $duelId): bool
    {
        return $this->duelInfo !== null && ($this->duelInfo['duelId'] ?? null) === $duelId->toString();
    }

    private function laterPhase(mixed $current, DuelPhase $next): string
    {
        $currentPhase = \is_string($current) ? DuelPhase::tryFrom($current) : null;

        return $currentPhase !== null && $currentPhase->rank() > $next->rank() ? $currentPhase->value : $next->value;
    }

    /**
     * The placed tile at a position, from the cache or the Tile aggregate; null when there is none.
     */
    private function tileAt(FieldPlace $position, MessageContext $context): ?Tile
    {
        $tileId = $this->tiles[$position->toString()] ?? null;
        if ($tileId === null) {
            return null;
        }
        if (\is_string($tileId)) {
            $tileId = Uuid::fromString($tileId);
        }

        try {
            return $this->getTile($tileId, $context);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<Item> $drawn
     */
    private function resolveFateweaver(FieldPlace $fieldPlace, array $drawn, Item $chosen, Bag $bag): void
    {
        $this->items[$fieldPlace->toString()] = $chosen;
        foreach ($drawn as $item) {
            if (!$item->itemId->equals($chosen->itemId)) {
                $bag->returnItem($item);
            }
        }
        $this->pendingFateweaver = null;
    }

    /**
     * Rebuild the teleportationConnections array from teleportationGatePositions.
     */
    private function rebuildTeleportationConnections(): void
    {
        $this->teleportationConnections = [];

        foreach ($this->teleportationGatePositions as $gate1) {
            $gate1String = $gate1->toString();
            /** @var list<FieldPlace> $connections */
            $connections = [];

            foreach ($this->teleportationGatePositions as $gate2) {
                if (!$gate1->equals($gate2)) {
                    $connections[] = $gate2;
                }
            }

            $this->teleportationConnections[$gate1String] = $connections;
        }
    }

    /**
     * Calculate possible destinations from a given position.
     *
     * @param FieldPlace $playerPosition The player's current position
     * @param MessageBus $messageBus MessageBus for queries
     * @return list<FieldPlace> List of field places the player can move to
     */
    private function calculatePossibleDestinations(FieldPlace $playerPosition, MessageBus $messageBus): array
    {
        // If transitions aren't calculated yet, calculate them now
        if (isset($this->tiles[$playerPosition->toString()]) && !isset($this->transitions[$playerPosition->toString()])) {
            $tileId = $this->tiles[$playerPosition->toString()];
            // Ensure tileId is a Uuid object (might be string from JSON deserialization)
            if (\is_string($tileId)) {
                $tileId = Uuid::fromString($tileId);
            }
            $tile = $this->getTileFromBus($tileId, $messageBus);
            $this->updateTransitionsForTile($playerPosition, $tile->getOrientation());
        }

        $destinations = [];

        // Get all transitions from player's current position
        $transitions = $this->getTransitionsFrom(FieldPlace::fromAnything($playerPosition));

        // Convert destination strings to FieldPlace objects
        foreach ($transitions as $destinationString) {
            $destinations[] = FieldPlace::fromString($destinationString);
        }

        return $destinations;
    }

    /**
     * Calculate possible destinations from a given position using MessageContext.
     *
     * @param FieldPlace $playerPosition The player's current position
     * @param MessageContext $messageContext MessageContext for commands
     * @return list<FieldPlace> List of field places the player can move to
     */
    private function calculatePossibleDestinationsWithMessageContext(FieldPlace $playerPosition, MessageContext $messageContext): array
    {
        // If transitions aren't calculated yet, calculate them now
        if (isset($this->tiles[$playerPosition->toString()]) && !isset($this->transitions[$playerPosition->toString()])) {
            $tileId = $this->tiles[$playerPosition->toString()];
            // Ensure tileId is a Uuid object (might be string from JSON deserialization)
            if (\is_string($tileId)) {
                $tileId = Uuid::fromString($tileId);
            }
            $tile = $this->getTile($tileId, $messageContext);
            $this->updateTransitionsForTile($playerPosition, $tile->getOrientation());
        }

        $destinations = [];

        // Get all transitions from player's current position
        $transitions = $this->getTransitionsFrom(FieldPlace::fromAnything($playerPosition));

        // Convert destination strings to FieldPlace objects
        foreach ($transitions as $destinationString) {
            $destinations[] = FieldPlace::fromString($destinationString);
        }

        return $destinations;
    }

    /**
     * Get tile using MessageBus for query context.
     *
     * @param Uuid $tileId The tile ID
     * @param MessageBus $messageBus MessageBus for queries
     * @return Tile The tile
     */
    private function getTileFromBus(Uuid $tileId, MessageBus $messageBus): Tile
    {
        // Check cache first
        $tileIdStr = $tileId->toString();
        if (isset($this->tilesCache[$tileIdStr])) {
            return $this->tilesCache[$tileIdStr];
        }

        // Get tile from database
        $tile = $messageBus->dispatch(new GetTile($tileId));
        if (!$tile instanceof Tile) {
            throw new \RuntimeException(sprintf('Tile with ID %s not found', $tileId->toString()));
        }

        // Cache it
        $this->tilesCache[$tileIdStr] = $tile;

        return $tile;
    }

    private function addAvailableFieldPlace(FieldPlace $siblingFieldPlace, MessageContext $messageContext): void
    {
        if (!\in_array($siblingFieldPlace, $this->availableFieldPlaces, strict: false)) {
            $this->availableFieldPlaces[] = $siblingFieldPlace;
            $this->availableFieldPlacesOrientation[$siblingFieldPlace->toString()] = $this->getAvailableFieldPlaceOrientation($siblingFieldPlace, $messageContext);

            // Now that this field place is available, check if any adjacent tiles should have transitions to it
            $this->updateTransitionsToNewAvailablePlace($siblingFieldPlace, $messageContext);
        }
    }

    private function removeAvailableFieldPlace(FieldPlace $fieldPlace): void
    {
        if (\in_array($fieldPlace, $this->availableFieldPlaces, strict: false)) {
            $this->availableFieldPlaces = array_values(array_filter(
                $this->availableFieldPlaces,
                static fn(FieldPlace $availableFieldPlace) => !$availableFieldPlace->equals($fieldPlace),
            ));
            unset($this->availableFieldPlacesOrientation[$fieldPlace->toString()]);

            // If this field place is in transitions as an empty space, it's being occupied now,
            // so update transitions. The new transitions will be calculated in updateTransitionsForTile.
            $fieldPlaceString = $fieldPlace->toString();
            foreach ($this->transitions as &$destinations) {
                if (\in_array($fieldPlaceString, $destinations, true)) {
                    // Remove the transition to this field place
                    $destinations = array_values(array_filter(
                        $destinations,
                        static fn(string $destination) => $destination !== $fieldPlaceString,
                    ));
                }
            }
        }
    }

    private function isFieldPlaceAlreadyTaken(FieldPlace $fieldPlace): bool
    {
        return isset($this->tiles[$fieldPlace->toString()]);
    }

    /**
     * @return array<int, Tile>
     */
    private function getSiblingTilesBySide(FieldPlace $fieldPlace, MessageContext $messageContext): array
    {
        $tiles = [];
        foreach ($fieldPlace->getAllSiblingsBySides() as $side => $siblingFieldPlace) {
            if ($this->isFieldPlaceAlreadyTaken($siblingFieldPlace)) {
                $tileId = $this->tiles[$siblingFieldPlace->toString()];
                // Ensure tileId is a Uuid object (might be string from JSON deserialization)
                if (\is_string($tileId)) {
                    $tileId = Uuid::fromString($tileId);
                }
                $tiles[$side] = $this->getTile($tileId, $messageContext);
            }
        }

        return $tiles;
    }

    private function getAvailableFieldPlaceOrientation(FieldPlace $fieldPlace, MessageContext $messageContext): TileOrientation
    {
        $siblingTiles = $this->getSiblingTilesBySide($fieldPlace, $messageContext);
        $orientation = [false, false, false, false];
        foreach ($siblingTiles as $siblingSideInt => $siblingTile) {
            $siblingSide = TileSide::from($siblingSideInt);
            if ($siblingTile->hasOpenedSideBySiblingSide($siblingSide)) {
                $orientation[$siblingSide->value] = true;
            }
        }

        return TileOrientation::fromArray($orientation);
    }

    /**
     * Updates transitions for a newly placed tile and adjacent tiles.
     */
    private function updateTransitionsForTile(FieldPlace $fieldPlace, TileOrientation $orientation): void
    {
        // Initialize transitions for this field place if not already set
        if (!isset($this->transitions[$fieldPlace->toString()])) {
            /** @var list<string> $emptyList */
            $emptyList = [];
            $this->transitions[$fieldPlace->toString()] = $emptyList;
        }

        // Check each side of the tile
        foreach (array_keys($orientation->getOrientation()) as $sideValue) {
            $side = TileSide::from($sideValue);

            // Skip if the side is not open
            if (!$orientation->isOpenedSide($side)) {
                continue;
            }

            $siblingFieldPlace = $fieldPlace->getSiblingBySide($side);
            $siblingFieldPlaceString = $siblingFieldPlace->toString();

            // Check if there's a tile on the adjacent field place
            if ($this->isFieldPlaceAlreadyTaken($siblingFieldPlace) && isset($this->tileOrientations[$siblingFieldPlaceString])) {
                $siblingTileOrientation = TileOrientation::fromCharacter($this->tileOrientations[$siblingFieldPlaceString]);
                if ($siblingTileOrientation->isOpenedSide($side->getOppositeSide())) {
                    $this->transitions[$fieldPlace->toString()][] = $siblingFieldPlaceString;

                    if (!isset($this->transitions[$siblingFieldPlaceString])) {
                        /** @var list<string> $emptyList */
                        $emptyList = [];
                        $this->transitions[$siblingFieldPlaceString] = $emptyList;
                    }

                    if (!\in_array($fieldPlace->toString(), $this->transitions[$siblingFieldPlaceString], true)) {
                        $this->transitions[$siblingFieldPlaceString][] = $fieldPlace->toString();
                    }
                } else {
                    $this->transitions[$fieldPlace->toString()] = array_values(array_diff($this->transitions[$fieldPlace->toString()] ?? [], [$siblingFieldPlaceString]));
                    $this->transitions[$siblingFieldPlaceString] = array_values(array_diff($this->transitions[$siblingFieldPlaceString] ?? [], [$fieldPlace->toString()]));
                }
                // Add transitions in both directions
                //                if (!\in_array($siblingFieldPlaceString, $this->transitions[$fieldPlace->toString()], true)) {
                //                    $this->transitions[$fieldPlace->toString()][] = $siblingFieldPlaceString;
                //                }

                // Initialize transitions for the adjacent field place if not already set
                //                if (!isset($this->transitions[$siblingFieldPlaceString])) {
                //                    $this->transitions[$siblingFieldPlaceString] = [];
                //                }

                // Add transition from the adjacent tile to this tile
                //                if (!\in_array($fieldPlace->toString(), $this->transitions[$siblingFieldPlaceString], true)) {
                //                    $this->transitions[$siblingFieldPlaceString][] = $fieldPlace->toString();
                //                }
            } else {
                // If there's no tile yet, but it's a valid place to move to (available field place),
                // add a transition to it
                if (\in_array($siblingFieldPlace, $this->availableFieldPlaces, strict: false)) {
                    if (!\in_array($siblingFieldPlaceString, $this->transitions[$fieldPlace->toString()], true)) {
                        $this->transitions[$fieldPlace->toString()][] = $siblingFieldPlaceString;
                    }
                }
            }
        }

        // If this is a teleportation gate, add transitions to all other teleportation gates
        $isCurrentPortal = false;
        foreach ($this->teleportationGatePositions as $portal) {
            if ($portal->equals($fieldPlace)) {
                $isCurrentPortal = true;
                break;
            }
        }

        if ($isCurrentPortal) {
            $currentPosition = $fieldPlace->toString();

            // Add transitions to all other teleportation gates
            foreach ($this->teleportationGatePositions as $portalPlace) {
                // Skip the current position
                if ($portalPlace->equals($fieldPlace)) {
                    continue;
                }

                $portalPosition = $portalPlace->toString();

                // Add bidirectional transitions
                if (!\in_array($portalPosition, $this->transitions[$currentPosition], true)) {
                    $this->transitions[$currentPosition][] = $portalPosition;
                }

                if (!isset($this->transitions[$portalPosition])) {
                    /** @var list<string> $emptyList */
                    $emptyList = [];
                    $this->transitions[$portalPosition] = $emptyList;
                }
                if (!\in_array($currentPosition, $this->transitions[$portalPosition], true)) {
                    $this->transitions[$portalPosition][] = $currentPosition;
                }
            }

            // Update teleportationConnections array for Movement bounded context
            $this->rebuildTeleportationConnections();
        }
    }

    /**
     * Updates transitions from adjacent occupied field places to a newly available field place.
     */
    private function updateTransitionsToNewAvailablePlace(FieldPlace $fieldPlace, MessageContext $messageContext): void
    {
        $fieldPlaceString = $fieldPlace->toString();

        // Check all adjacent field places
        foreach ($fieldPlace->getAllSiblingsBySides() as $side => $siblingFieldPlace) {
            // If there's no tile on the adjacent field place, skip it
            if (!$this->isFieldPlaceAlreadyTaken($siblingFieldPlace)) {
                continue;
            }

            $siblingFieldPlaceString = $siblingFieldPlace->toString();
            $siblingTileId = $this->tiles[$siblingFieldPlaceString];
            // Ensure tileId is a Uuid object (might be string from JSON deserialization)
            if (\is_string($siblingTileId)) {
                $siblingTileId = Uuid::fromString($siblingTileId);
            }
            $siblingTile = $this->getTile($siblingTileId, $messageContext);

            // Check if the adjacent tile has an open side facing this field place
            // Convert integer side to TileSide enum
            $tileSide = TileSide::from($side);
            if (!$siblingTile->hasOpenedSideBySiblingSide($tileSide)) {
                continue;
            }

            // Initialize transitions for the adjacent field place if not already set
            if (!isset($this->transitions[$siblingFieldPlaceString])) {
                /** @var list<string> $emptyList */
                $emptyList = [];
                $this->transitions[$siblingFieldPlaceString] = $emptyList;
            }

            // Add transition from the adjacent tile to this field place
            if (!\in_array($fieldPlaceString, $this->transitions[$siblingFieldPlaceString], true)) {
                $this->transitions[$siblingFieldPlaceString][] = $fieldPlaceString;
            }
        }
    }

    /**
     * Get places where a player can move to from current position.
     *
     * @param int $positionX Current X position
     * @param int $positionY Current Y position
     * @param Uuid $playerId Player ID
     * @return list<FieldPlace> List of places the player can move to
     */
    private function getAvailableMovesToPlaces(int $positionX, int $positionY, Uuid $playerId, MessageBus $messageBus): array
    {
        // Use the new method that accepts MessageBus
        return $this->getPossibleDestinationsWithBus($playerId, $messageBus);
    }

    /**
     * Get places where a player can place a tile from current position.
     *
     * @param int $positionX Current X position
     * @param int $positionY Current Y position
     * @param Uuid $playerId Player ID
     * @return list<FieldPlace> List of places where the player can place a tile
     */
    private function getAvailablePlaceTilePlaces(int $positionX, int $positionY, Uuid $playerId, MessageBus $messageBus): array
    {
        // Get available moves first
        $moveToPlaces = $this->getAvailableMovesToPlaces($positionX, $positionY, $playerId, $messageBus);

        // Filter to only include places that don't already have tiles
        $placeTilePlaces = [];
        foreach ($moveToPlaces as $place) {
            $placeStr = $place->toString();
            $hasTile = isset($this->tiles[$placeStr]);

            // Just check if there's no tile - remove availableFieldPlaces restriction
            if (!$hasTile) {
                $placeTilePlaces[] = $place;
            }
        }

        return $placeTilePlaces;
    }
}
