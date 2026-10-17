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
use App\Game\Movement\Commands\DeclareSprint;
use App\Game\Movement\Commands\LightningAttack;
use App\Game\Movement\Commands\MagicSwap;
use App\Game\Movement\Commands\MovePlayer;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\Commands\TeleportPlayer;
use App\Game\Movement\DoctrineDBAL\PlayerPositionMapType;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\Events\PlayersSwapped;
use App\Game\Movement\Events\PlayerTeleported;
use App\Game\Movement\Exception\InvalidMovementException;
use App\Game\Movement\Exception\NotYourTurnException;
use App\Game\Player\GetPlayer;
use App\Game\Player\PlayerStunned;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnActionPerformed;
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

    /**
     * Whether the tile holds an item that can be picked up (no guard, or a defeated one).
     *
     * @param array<string, mixed> $items
     */
    private static function hasPickableItemAt(array $items, string $positionKey): bool
    {
        if (!isset($items[$positionKey])) {
            return false;
        }
        $item = Item::fromAnything($items[$positionKey]);

        return $item->guardDefeated || $item->guardHP === 0;
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

        $field = $context->dispatch(new GetField($this->gameId));

        // Oracle "Fateweaver": the token of the new room must be chosen before anyone moves
        if ($field->getPendingFateweaver() !== null) {
            throw new InvalidMovementException('Choose the Fateweaver token first');
        }
        // Karak: Regent — a hero who opened a Battle Arena summons his rival before he walks on
        if ($field->getPendingArena() !== null) {
            throw new InvalidMovementException('Choose your rival on the arena first');
        }

        // Create movement service to check transitions
        $movementService = $this->createMovementService($context);

        // Check if destination has a monster
        $items = $field->getItems();
        $destinationKey = $command->toPosition->toString();
        $destinationHasMonster = false;
        $monsterItem = null;
        if (isset($items[$destinationKey])) {
            $destinationItem = Item::fromAnything($items[$destinationKey]);
            // Only an undefeated monster blocks the way / starts a battle
            if ($destinationItem->guardHP > 0 && !$destinationItem->guardDefeated) {
                $destinationHasMonster = true;
                $monsterItem = $destinationItem;
            }
        }

        // Get player HP
        $player = $context->dispatch(new GetPlayer($command->playerId));
        $playerHp = $player->getHP();

        // Thief "Stealth": only a hero with Stealth may enter a monster tile without fighting;
        // the sneak is then recorded as a regular move and no battle starts
        $sneakedPastMonster = null;
        if ($command->ignoreMonster && $destinationHasMonster && $monsterItem !== null) {
            if ($player->getSkills()?->canSneakPastMonsters() !== true) {
                throw new InvalidMovementException('Only the Thief can sneak past a monster (Stealth)');
            }
            $sneakedPastMonster = $monsterItem;
            $destinationHasMonster = false;
        }

        // Wizard "Astral Walking": without a door, a hero who walks through walls may still step onto an adjacent placed tile
        $canTransition = $movementService->canTransition($currentPosition, $command->toPosition);
        $throughWall = !$canTransition
            && $player->getSkills()?->walksThroughWalls() === true
            && $movementService->canWalkThroughWallTo($currentPosition, $command->toPosition);

        // Validate movement
        $validator = new MovementValidator();
        $validator->validateMovement(
            playerId: $command->playerId,
            currentPlayerId: $currentPlayerId,
            fromPosition: $command->fromPosition,
            toPosition: $command->toPosition,
            actualPlayerPosition: $currentPosition,
            hasMovedAfterBattle: $this->hasMovedAfterBattle[$playerId] ?? false,
            canTransition: $canTransition || $throughWall,
            playerHp: $playerHp,
            destinationHasMonster: $destinationHasMonster,
        );

        // The turn decides first whether this step may be taken at all (an ended turn, the moves spent, a reward
        // waiting, a step after a fight): a refused step leaves the hero where he stands
        $turn = $context->dispatch(new GetTurn($command->turnId));
        if ($turn === null) {
            throw new InvalidMovementException('No such turn');
        }
        $turn->assertCanPerform(TurnAction::MOVE);

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
            $hasPickableItem = self::hasPickableItemAt($items, $destinationKey);

            $additionalData = [
                'from' => $currentPosition->toString(),
                'to' => $command->toPosition->toString(),
            ];
            if ($sneakedPastMonster !== null) {
                $additionalData['sneakedPastMonster'] = true;
                $additionalData['heroAbility'] = 'stealth';
                $additionalData['monster'] = [
                    'name' => $sneakedPastMonster->name->value,
                    'type' => $sneakedPastMonster->type->value,
                    'hp' => $sneakedPastMonster->guardHP,
                ];
            }
            if ($throughWall) {
                $additionalData['throughWall'] = true;
                $additionalData['heroAbility'] = isset($additionalData['heroAbility'])
                    ? $additionalData['heroAbility'] . '+astral_walking'
                    : 'astral_walking';
            }

            $context->dispatch(new PerformTurnAction(
                turnId: $command->turnId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                action: TurnAction::MOVE,
                tileId: null,
                additionalData: $additionalData,
                at: new \DateTimeImmutable(),
                deferTurnEnd: $hasPickableItem,
            ));
        }

        // Start battle if moving to a monster tile
        if ($destinationHasMonster && $monsterItem !== null && !$command->ignoreMonster) {
            $turnId = $context->dispatch(new GetCurrentTurn($command->gameId));
            if ($turnId === null) {
                throw new InvalidMovementException('No active turn to fight in');
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

    /**
     * Warlock "Magic Swap": trade places with another hero. Costs all 4 moves, so only as the first action of the turn;
     * the reward on the new tile may still be picked up and a fountain still heals when the turn ends there.
     *
     * @throws GameAlreadyFinishedException
     * @throws NotYourTurnException
     * @throws InvalidMovementException
     * @throws \DomainException
     */
    #[Handler]
    public function magicSwap(MagicSwap $command, MessageContext $context): void
    {
        $game = $context->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            throw new GameAlreadyFinishedException();
        }

        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$currentPlayerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        $currentTurnId = $context->dispatch(new GetCurrentTurn($command->gameId));
        if ($currentTurnId === null || !$currentTurnId->equals($command->turnId)) {
            throw new InvalidMovementException('Invalid turn ID');
        }

        $player = $context->dispatch(new GetPlayer($command->playerId));
        if ($player->getSkills()?->canMagicSwap() !== true) {
            throw new \DomainException('Only the Warlock can swap places (Magic Swap)');
        }

        if ($command->targetPlayerId->equals($command->playerId)) {
            throw new InvalidMovementException('Magic Swap needs another hero to swap places with');
        }

        $playerId = $command->playerId->toString();
        $targetId = $command->targetPlayerId->toString();
        $playerFrom = $this->playerPositions[$playerId] ?? null;
        $playerTo = $this->playerPositions[$targetId] ?? null;
        if ($playerFrom === null || $playerTo === null) {
            throw new InvalidMovementException('Player position not found');
        }

        $turn = $context->dispatch(new GetTurn($command->turnId));
        if ($turn === null || $turn->getActions() !== []) {
            throw new InvalidMovementException('Magic Swap costs 4 moves and is only possible at the beginning of the turn');
        }

        $this->playerPositions[$playerId] = $playerTo;
        $this->playerPositions[$targetId] = $playerFrom;

        $context->dispatch(new PlayersSwapped(
            gameId: $command->gameId,
            playerId: $command->playerId,
            targetPlayerId: $command->targetPlayerId,
            playerFrom: $playerFrom,
            playerTo: $playerTo,
        ));

        // The swap spends the whole turn; a pickable item on the new tile keeps the turn open for PICK_ITEM
        $field = $context->dispatch(new GetField($this->gameId));
        $context->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: TurnAction::MAGIC_SWAP,
            tileId: null,
            additionalData: [
                'ability' => 'magic_swap',
                'heroAbility' => 'magic_swap',
                'targetPlayerId' => $targetId,
                'from' => $playerFrom->toString(),
                'to' => $playerTo->toString(),
            ],
            at: new \DateTimeImmutable(),
            deferTurnEnd: self::hasPickableItemAt($field->getItems(), $playerTo->toString()),
        ));
    }

    /**
     * Baron Markul "Lightning Attack": teleport onto any tile with a monster and fight it. Costs all 4 moves,
     * so only as the first action of the turn; a fight that is not won leaves him to choose a retreat (Field).
     *
     * @throws GameAlreadyFinishedException
     * @throws NotYourTurnException
     * @throws InvalidMovementException
     * @throws \DomainException
     */
    #[Handler]
    public function lightningAttack(LightningAttack $command, MessageContext $context): void
    {
        $game = $context->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            throw new GameAlreadyFinishedException();
        }

        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$currentPlayerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        $currentTurnId = $context->dispatch(new GetCurrentTurn($command->gameId));
        if ($currentTurnId === null || !$currentTurnId->equals($command->turnId)) {
            throw new InvalidMovementException('Invalid turn ID');
        }

        $player = $context->dispatch(new GetPlayer($command->playerId));
        if ($player->getSkills()?->lightningAttack() !== true) {
            throw new \DomainException('Only Baron Markul can strike like lightning (Lightning Attack)');
        }
        if ($player->getHP() <= 0) {
            throw new InvalidMovementException('A stunned hero cannot attack');
        }

        $playerId = $command->playerId->toString();
        $from = $this->playerPositions[$playerId] ?? null;
        if ($from === null) {
            throw new InvalidMovementException('Player position not found');
        }

        $turn = $context->dispatch(new GetTurn($command->turnId));
        if ($turn === null || $turn->getActions() !== []) {
            throw new InvalidMovementException('Lightning Attack costs 4 moves and is only possible at the beginning of the turn');
        }

        $targetKey = $command->targetPosition->toString();
        if ($targetKey === $from->toString()) {
            throw new InvalidMovementException('Lightning Attack needs another tile');
        }
        $items = $context->dispatch(new GetField($this->gameId))->getItems();
        $monster = isset($items[$targetKey]) ? Item::fromAnything($items[$targetKey]) : null;
        if ($monster === null || $monster->guardHP <= 0 || $monster->guardDefeated) {
            throw new InvalidMovementException('There is no monster on that tile');
        }

        $this->playerPositions[$playerId] = $command->targetPosition;
        $context->dispatch(new PlayerMoved(
            gameId: $command->gameId,
            playerId: $command->playerId,
            fromPosition: $from,
            toPosition: $command->targetPosition,
            movedAt: new \DateTimeImmutable(),
        ));

        $context->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: TurnAction::LIGHTNING_ATTACK,
            tileId: null,
            additionalData: [
                'from' => $from->toString(),
                'to' => $targetKey,
                'monster' => ['name' => $monster->name->value, 'type' => $monster->type->value, 'hp' => $monster->guardHP],
                'heroAbility' => 'lightning_attack',
            ],
            at: new \DateTimeImmutable(),
        ));

        $context->dispatch(new StartBattle(
            battleId: Uuid::v7(),
            gameId: $command->gameId,
            playerId: $command->playerId,
            turnId: $command->turnId,
            monster: $monster,
            fromPosition: $from,
            toPosition: $command->targetPosition,
            lightning: true,
        ));
    }

    /**
     * Hanna "Sprint": declared as the first action of the turn; the turn then allows 8 moves and no tile exploration.
     *
     * @throws GameAlreadyFinishedException
     * @throws NotYourTurnException
     * @throws InvalidMovementException
     * @throws \DomainException
     */
    #[Handler]
    public function declareSprint(DeclareSprint $command, MessageContext $context): void
    {
        $game = $context->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            throw new GameAlreadyFinishedException();
        }

        $currentPlayerId = $context->dispatch(new GetCurrentPlayer($command->gameId));
        if ($currentPlayerId === null || !$currentPlayerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        $currentTurnId = $context->dispatch(new GetCurrentTurn($command->gameId));
        if ($currentTurnId === null || !$currentTurnId->equals($command->turnId)) {
            throw new InvalidMovementException('Invalid turn ID');
        }

        $player = $context->dispatch(new GetPlayer($command->playerId));
        if ($player->getSkills()?->sprints() !== true) {
            throw new \DomainException('Only Hanna can sprint (Sprint)');
        }

        $turn = $context->dispatch(new GetTurn($command->turnId));
        if ($turn === null || $turn->getActions() !== []) {
            throw new InvalidMovementException('Sprint is declared at the beginning of the turn');
        }

        $context->dispatch(new PerformTurnAction(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: TurnAction::SPRINT,
            tileId: null,
            additionalData: ['heroAbility' => 'sprint', 'maxMoves' => 8],
            at: new \DateTimeImmutable(),
        ));
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
        // Swordsman "Unstoppable": a battle with a 6 on the dice does not lock the rest of the turn
        if ($event->monsterHP > 0 && !$event->unstoppable) {
            // Mark that the player has battled this turn
            $this->hasMovedAfterBattle[$event->playerId->toString()] = true;
        }
    }

    /**
     * Valduk "Tireless": leaving the loot of a won fight unlocks the rest of the turn.
     */
    #[Handler]
    public function onTurnActionPerformed(TurnActionPerformed $event): void
    {
        if ($event->action === TurnAction::TIRELESS) {
            $this->hasMovedAfterBattle[$event->playerId->toString()] = false;
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
