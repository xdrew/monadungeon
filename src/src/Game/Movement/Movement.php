<?php

declare(strict_types=1);

namespace App\Game\Movement;

use App\Game\Battle\BattleCompleted;
use App\Game\Battle\StartBattle;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\Field\Tile;
use App\Game\Field\TileFeature;
use App\Game\Field\TilePlaced;
use App\Game\GameLifecycle\Error\GameAlreadyFinishedException;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GameStarted;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Movement\Commands\MovePlayer;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\Commands\TeleportPlayer;
use App\Game\Movement\DoctrineDBAL\PlayerPositionMapType;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\Events\PlayerTeleported;
use App\Game\Movement\Exception\InvalidMovementException;
use App\Game\Movement\Exception\NotYourTurnException;
use App\Game\Player\GetPlayer;
use App\Game\Player\PlayerStunned;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
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
use Telephantast\MessageBus\MessageContext;

#[Entity]
#[Table(schema: 'movement')]
#[FindBy(['gameId' => new Property('gameId')])]
class Movement extends AggregateRoot
{
    /**
     * @var array<string, FieldPlace>
     * Maps player ID to their current position
     */
    #[Column(type: PlayerPositionMapType::class, columnDefinition: 'jsonb')]
    private array $playerPositions = [];

    /**
     * @var array<string, list<string>>
     * Maps from a field place to available destination field places
     * Structure: [sourceFieldPlace => [destinationFieldPlace1, destinationFieldPlace2, ...]]
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $transitions = [];

    /**
     * @var array<string, list<FieldPlace>>
     * Maps teleportation gate positions to their connected gates
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $teleportationConnections = [];

    /**
     * @var array<string, array{stunned: bool, postBattle: bool}>
     * Movement restrictions per player
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $movementRestrictions = [];

    /**
     * @var array<string, bool>
     * Tracks which players have moved after battle this turn
     */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $hasMovedAfterBattle = [];

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $gameId,
    ) {}

    #[Handler]
    public static function create(GameCreated $event): self
    {
        return new self($event->gameId);
    }

    #[Handler]
    public function initializeStartingPositions(GameStarted $event, MessageContext $context): void
    {
        // All players start at position 0,0
        $game = $context->dispatch(new GetGame($event->gameId));
        $players = $game->getPlayers();
        foreach ($players as $playerId) {
            $this->playerPositions[$playerId->toString()] = new FieldPlace(0, 0);
        }
    }

    #[Handler]
    public function movePlayer(MovePlayer $command, MessageContext $context): void
    {
        // Check if game is finished
        $game = $context->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            throw new GameAlreadyFinishedException();
        }
        
        // Validate it's the player's turn
        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$currentPlayerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        $playerId = $command->playerId->toString();
        $currentPosition = $this->playerPositions[$playerId] ?? null;

        if ($currentPosition === null) {
            throw new InvalidMovementException('Player position not found');
        }

        // Create movement service to check transitions
        $movementService = $this->createMovementService($context);

        // Check if destination has a monster
        $field = $context->dispatch(new GetField($this->gameId));
        $items = $field->getItems();
        $destinationKey = $command->toPosition->toString();
        $destinationHasMonster = isset($items[$destinationKey]);
        $monsterItem = null;
        if ($destinationHasMonster && !$command->ignoreMonster) {
            $monsterItem = Item::fromAnything($items[$destinationKey]);
            // Only start battle if monster is not already defeated
            if ($monsterItem->guardHP > 0 && !$monsterItem->guardDefeated) {
                $destinationHasMonster = true;
            } else {
                $destinationHasMonster = false;
            }
        }

        // Get player HP
        $player = $context->dispatch(new GetPlayer($command->playerId));
        $playerHp = $player->getHP();

        // Validate movement
        $validator = new MovementValidator();
        $validator->validateMovement(
            playerId: $command->playerId,
            currentPlayerId: $currentPlayerId,
            fromPosition: $command->fromPosition,
            toPosition: $command->toPosition,
            actualPlayerPosition: $currentPosition,
            hasMovedAfterBattle: $this->hasMovedAfterBattle[$playerId] ?? false,
            canTransition: $movementService->canTransition($currentPosition, $command->toPosition),
            playerHp: $playerHp,
            destinationHasMonster: $destinationHasMonster,
        );

        // Update position
        $this->playerPositions[$playerId] = $command->toPosition;

        // Dispatch event
        $context->dispatch(new PlayerMoved(
            gameId: $command->gameId,
            playerId: $command->playerId,
            fromPosition: $currentPosition,
            toPosition: $command->toPosition,
            movedAt: new \DateTimeImmutable(),
            isBattleReturn: false,
            isTilePlacementMove: $command->isTilePlacementMove,
        ));

        // Record turn action if not moving to battle
        if (!$destinationHasMonster) {
            try {
                $context->dispatch(new PerformTurnAction(
                    turnId: $command->turnId,
                    gameId: $command->gameId,
                    playerId: $command->playerId,
                    action: TurnAction::MOVE,
                    tileId: null,
                    additionalData: [
                        'from' => $currentPosition->toString(),
                        'to' => $command->toPosition->toString(),
                    ],
                    at: new \DateTimeImmutable(),
                ));
            } catch (\RuntimeException) {
                // Ignore turn-related errors - the turn might have ended
                // but we still want to allow the movement to complete
            }
        }

        // Start battle if moving to a monster tile
        if ($destinationHasMonster && $monsterItem !== null && !$command->ignoreMonster) {
            $turnId = $context->dispatch(new GetCurrentTurn($command->gameId));
            if ($turnId === null) {
                // No active turn, skip battle
                return;
            }

            $context->dispatch(new StartBattle(
                battleId: Uuid::v7(),
                gameId: $command->gameId,
                playerId: $command->playerId,
                turnId: $turnId,
                monster: $monsterItem,
                fromPosition: $currentPosition,
                toPosition: $command->toPosition,
            ));
        }
    }

    #[Handler]
    public function teleportPlayer(TeleportPlayer $command, MessageContext $context): void
    {
        $playerId = $command->playerId->toString();

        // Update position without validation (teleportation bypasses normal movement rules)
        $previousPosition = $this->playerPositions[$playerId] ?? new FieldPlace(0, 0);
        $this->playerPositions[$playerId] = $command->to;

        // Dispatch event
        $context->dispatch(new PlayerTeleported(
            gameId: $command->gameId,
            playerId: $command->playerId,
            from: $previousPosition,
            to: $command->to,
        ));
    }

    #[Handler]
    public function resetPlayerPosition(ResetPlayerPosition $command): void
    {
        $this->playerPositions[$command->playerId->toString()] = $command->position;
    }

    #[Handler]
    public function onBattleCompleted(BattleCompleted $event): void
    {
        // Only restrict movement after ACTUAL battles with monsters (guardHP > 0)
        // Chests typically have guardHP = 0 and should not restrict movement
        if ($event->monsterHP > 0) {
            // Mark that the player has battled this turn
            $this->hasMovedAfterBattle[$event->playerId->toString()] = true;
        }
    }

    #[Handler]
    public function onTurnStarted(TurnStarted $event): void
    {
        // Reset battle movement restrictions for the new turn
        $this->hasMovedAfterBattle[$event->playerId->toString()] = false;
    }

    #[Handler]
    public function onPlayerMoved(PlayerMoved $event): void
    {
        // Handle position updates from battle returns
        if ($event->isBattleReturn) {
            // Update player position when they're moved back after losing a battle
            $this->playerPositions[$event->playerId->toString()] = $event->toPosition;
        }
    }

    // TODO: Handle PlayerStunned event when Field no longer handles it
    // Currently Field handles PlayerStunned to end turn
    // #[Handler]
    // public function handlePlayerStunned(PlayerStunned $event): void
    // {
    //     $playerId = $event->playerId->toString();
    //     $this->movementRestrictions[$playerId] = [
    //         'stunned' => true,
    //         'postBattle' => $this->movementRestrictions[$playerId]['postBattle'] ?? false,
    //     ];
    // }

    // TODO: Create separate event or enable when Field is simplified
    // #[Handler]
    public function updateTransitionsNew(TilePlaced $event, MessageContext $context): void
    {
        // This will be called when tiles are placed to update the movement graph
        // For now, we'll need to query the field to rebuild transitions
        $field = $context->dispatch(new GetField($event->gameId));

        // Rebuild transitions based on current field state
        $this->transitions = $this->calculateTransitions($field, $context);

        // Update teleportation connections if this tile has a portal
        $tile = $this->getTileFromField($field, $event->tileId);
        if ($tile !== null && \in_array(TileFeature::TELEPORTATION_GATE, $tile->getFeatures(), true)) {
            $this->updateTeleportationConnections($field);
        }
    }

    #[Handler]
    public function getPlayerPosition(GetPlayerPosition $query): FieldPlace
    {
        $position = $this->playerPositions[$query->playerId->toString()] ?? null;

        if ($position === null) {
            throw new \RuntimeException('Player position not found');
        }

        return $position;
    }

    #[Handler]
    public function getAllPlayerPositions(GetAllPlayerPositions $query): array
    {
        return $this->playerPositions;
    }

    private function createMovementService(MessageContext $context): MovementService
    {
        // Get field to access transitions
        $field = $context->dispatch(new GetField($this->gameId));

        // Get transitions from field
        $transitions = $field->getDebugTransitions();

        // Get items from field for movement validation
        $items = $field->getItems();

        /** @var array<string, list<string>> $transitions */
        return new MovementService(
            transitions: $transitions,
            placedTiles: $field->getPlacedTiles(),
            tilesCache: [], // Not needed for movement validation
            playerPositions: $this->playerPositions,
            teleportationConnections: $field->getTeleportationConnections(),
            items: $items,
        );
    }

    private function getPlayerStunnedStatus(Uuid $playerId): bool
    {
        $restrictions = $this->movementRestrictions[$playerId->toString()] ?? null;

        return $restrictions['stunned'] ?? false;
    }

    /**
     * @return array<string, list<string>>
     * @psalm-suppress UnusedParam
     */
    private function calculateTransitions(Field $field, MessageContext $context): array
    {
        // TODO: Implement transition calculation based on field state
        // This logic will be moved from Field::updateTransitions
        // For now, return empty array
        return [];
    }

    /**
     * @psalm-suppress UnusedParam
     */
    private function updateTeleportationConnections(Field $field): void
    {
        // TODO: Calculate teleportation connections from field
        // This logic will be moved from Field
        // For now, do nothing
    }

    /**
     * @psalm-suppress UnusedParam
     */
    private function getTileFromField(Field $field, Uuid $tileId): ?Tile
    {
        // TODO: Query tile from field
        // For now, return null
        return null;
    }
}
