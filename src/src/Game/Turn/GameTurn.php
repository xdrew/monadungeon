<?php

declare(strict_types=1);

namespace App\Game\Turn;

use App\Game\GameLifecycle\Error\GameAlreadyFinishedException;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\NextTurn;
use App\Game\Turn\Error\InvalidTurnActionException;
use App\Game\Turn\Error\NotYourTurnException;
use App\Game\Turn\Error\TurnAlreadyEndedException;
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

#[Entity]
#[Table(schema: 'game_turn')]
#[FindBy(['turnId' => new Property('turnId')])]
class GameTurn extends AggregateRoot
{
    private const int MAX_ACTIONS_PER_TURN = 4;

    /** @var array<int, array{action: string, tileId: ?string, additionalData: ?array, performedAt: string}> */
    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $actions = [];

    #[Column(type: Types::INTEGER, nullable: false)]
    private int $performedActionsCount = 0;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: false)]
    private \DateTimeImmutable $startTime;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    private int $actionsRemaining = 0;

    private int $maxActions = 0;

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $turnId,
        #[Column(type: UuidType::class)]
        private readonly Uuid $gameId,
        #[Column(type: UuidType::class)]
        private readonly Uuid $playerId,
        #[Column(type: Types::INTEGER)]
        private readonly int $turnNumber,
    ) {
        $this->startTime = new \DateTimeImmutable();
    }

    #[Handler]
    public static function start(StartTurn $command, MessageContext $messageContext): self
    {
        $turn = new self(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            turnNumber: $command->turnNumber,
        );

        $messageContext->dispatch(new TurnStarted(
            turnId: $command->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            turnNumber: $command->turnNumber,
            startTime: $command->at,
        ));

        return $turn;
    }

    #[Handler]
    public function performAction(PerformTurnAction $command, MessageContext $messageContext): void
    {
        if (!$this->playerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        // Special handling for battle actions
        if ($command->action === TurnAction::FIGHT_MONSTER) {
            // If the turn has already ended, just record the battle data without throwing an exception
            // This allows battle results to be recorded for history/stats without causing errors
            $this->actions[] = [
                'action' => $command->action->value,
                'tileId' => $command->tileId?->toString(),
                'additionalData' => $command->additionalData,
                'performedAt' => $command->at->format(\DateTimeInterface::ATOM),
            ];

            // Only process the full battle flow if the turn hasn't ended yet
            if ($this->endTime === null) {
                // Dispatch the turn action performed event
                $messageContext->dispatch(new TurnActionPerformed(
                    turnId: $this->turnId,
                    gameId: $command->gameId,
                    playerId: $command->playerId,
                    action: $command->action,
                    performedAt: $command->at,
                    tileId: $command->tileId,
                    additionalData: $command->additionalData,
                ));

                // We no longer automatically end the turn after a battle
                // The client will show the battle results modal and then call end-turn endpoint
                // when the player closes the modal or clicks OK.

                /* Commented out automatic turn ending after battle
                $this->endTurn($command->at);
                $messageContext->dispatch(new TurnEnded(
                    turnId: $this->turnId,
                    gameId: $command->gameId,
                    playerId: $command->playerId,
                    endTime: $command->at,
                ));

                // Ensure we move to the next player's turn after a battle
                $messageContext->dispatch(new NextTurn($command->gameId));
                */
            }

            return;
        }

        // Special handling for PICK_ITEM after battle
        if ($command->action === TurnAction::PICK_ITEM && $this->hasBattleInTurn()) {
            // Record the action without normal restrictions
            $this->actions[] = [
                'action' => $command->action->value,
                'tileId' => $command->tileId?->toString(),
                'additionalData' => $command->additionalData,
                'performedAt' => $command->at->format(\DateTimeInterface::ATOM),
            ];

            // Always process PICK_ITEM after battle, even if turn might have ended
            // The Field.php handler will manage the turn ending
            $messageContext->dispatch(new TurnActionPerformed(
                turnId: $this->turnId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                action: $command->action,
                performedAt: $command->at,
                tileId: $command->tileId,
                additionalData: $command->additionalData,
            ));

            return;
        }

        // For all other actions, ensure the turn hasn't ended
        if ($this->endTime !== null) {
            throw new TurnAlreadyEndedException();
        }

        // Check if the action is allowed based on turn action rules
        $lastAction = $this->getLastAction();
        if (!$command->action->isAllowedAfter($lastAction)) {
            throw new InvalidTurnActionException($command->action, $lastAction);
        }

        $this->actions[] = [
            'action' => $command->action->value,
            'tileId' => $command->tileId?->toString(),
            'additionalData' => $command->additionalData,
            'performedAt' => $command->at->format(\DateTimeInterface::ATOM),
        ];

        $messageContext->dispatch(new TurnActionPerformed(
            turnId: $this->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            action: $command->action,
            performedAt: $command->at,
            tileId: $command->tileId,
            additionalData: $command->additionalData,
        ));

        // Check if a battle occurred in this turn - if so, don't auto-end turn based on action count
        $hasBattleInTurn = $this->hasBattleInTurn();

        // Only use action count to end turn if no battle occurred
        if ((!$hasBattleInTurn && $this->performedActionsCount >= self::MAX_ACTIONS_PER_TURN) || $command->action->isEndOfTurn()) {
            $this->endTurn($command->at);
            $messageContext->dispatch(new TurnEnded(
                turnId: $this->turnId,
                gameId: $command->gameId,
                playerId: $command->playerId,
                endTime: $command->at,
            ));

            // If this is an end-of-turn action, also dispatch the NextTurn event
            if ($command->action->isEndOfTurn()) {
                $messageContext->dispatch(new NextTurn($command->gameId, $command->at));
            }
        }
    }

    #[Handler]
    public function updateActionCounter(TurnActionPerformed $event): void
    {
        if (!$this->playerId->equals($event->playerId)) {
            throw new NotYourTurnException();
        }

        // Silently ignore actions if the turn has already ended
        // This prevents exceptions when battle actions come through
        if ($this->endTime !== null) {
            return;
        }

        // Do not increment action counter for FIGHT_MONSTER
        // This prevents automatic turn ending when there's a battle
        // so the client can display the battle dialog before moving to next turn
        if ($event->action === TurnAction::FIGHT_MONSTER) {
            // Don't increase the counter, let the player end the turn manually
            // after viewing the battle dialog
            return;
        }

        if ($event->action->isIncreasesActionCounter()) {
            ++$this->performedActionsCount;
        }
    }

    #[Handler]
    public function end(EndTurn $command, MessageContext $messageContext): void
    {
        // Check if game is finished
        $game = $messageContext->dispatch(new GetGame($command->gameId));
        if ($game->getStatus()->isFinished()) {
            // Game is already finished, silently ignore the end turn request
            return;
        }
        
        if (!$this->playerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        if ($this->endTime !== null) {
            return;
        }

        $this->endTurn($command->at);
        $messageContext->dispatch(new TurnEnded(
            turnId: $this->turnId,
            gameId: $command->gameId,
            playerId: $command->playerId,
            endTime: $command->at,
        ));

        // NextTurn will be dispatched by Game aggregate when it handles TurnEnded event
    }

    #[Handler]
    public function getInstance(GetTurn $query): self
    {
        return $this;
    }

    public function getTurnId(): Uuid
    {
        return $this->turnId;
    }

    public function getGameId(): Uuid
    {
        return $this->gameId;
    }

    public function getPlayerId(): Uuid
    {
        return $this->playerId;
    }

    public function getTurnNumber(): int
    {
        return $this->turnNumber;
    }

    public function getActions(): array
    {
        return $this->actions;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function isEnded(): bool
    {
        return $this->endTime !== null;
    }

    /**
     * Check if a battle occurred during this turn.
     */
    public function hasBattleInTurn(): bool
    {
        foreach ($this->actions as $action) {
            if ($action['action'] === TurnAction::FIGHT_MONSTER->value) {
                return true;
            }
        }

        return false;
    }

    // Test mode methods - only for use in tests

    /**
     * Set actions remaining for testing purposes.
     * @internal Only for use in test mode
     */
    public function setTestActionsRemaining(int $actions): void
    {
        $this->actionsRemaining = max(0, $actions);
    }

    /**
     * Set max actions for testing purposes.
     * @internal Only for use in test mode
     */
    public function setTestMaxActions(int $maxActions): void
    {
        $this->maxActions = max(1, $maxActions);
    }

    private function getLastAction(): ?TurnAction
    {
        if ($this->actions === []) {
            return null;
        }

        $lastAction = end($this->actions);

        return TurnAction::from($lastAction['action']);
    }

    private function endTurn(\DateTimeImmutable $at): void
    {
        $this->endTime = $at;
    }
}
