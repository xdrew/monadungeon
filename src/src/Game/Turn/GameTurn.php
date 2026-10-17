<?php

declare(strict_types=1);

namespace App\Game\Turn;

use App\Game\Field\GetField;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\NextTurn;
use App\Game\Player\GetPlayer;
use App\Game\Turn\Error\InvalidTurnActionException;
use App\Game\Turn\Error\NotYourTurnException;
use App\Game\Turn\Error\TurnAlreadyEndedException;
use App\Game\Turn\Error\UnplacedTileException;
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

    #[Column(type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $pendingItemPickup = false;

    /**
     * Hanna "Sprint": this turn has up to 8 moves and no tile exploration.
     */
    #[Column(type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $sprint = false;

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

    /**
     * Swordsman "Unstoppable": a battle after which the turn simply goes on.
     *
     * @param array{action: string, tileId: ?string, additionalData: ?array, performedAt: string} $action
     */
    private static function isUnstoppableBattle(array $action): bool
    {
        /** @var array<string, mixed> $additionalData */
        $additionalData = $action['additionalData'] ?? [];

        return $action['action'] === TurnAction::FIGHT_MONSTER->value
            && ($additionalData['unstoppable'] ?? false) === true;
    }

    /**
     * Actions that do not change what may follow: an Unstoppable fight, a spell (it costs no move),
     * the Curse of Karak bookkeeping and the Karak: Regent bookkeeping (Cursed Room roll, duel, duel loot).
     *
     * @param array{action: string, tileId: ?string, additionalData: ?array, performedAt: string} $action
     */
    private static function isBookkeepingAction(array $action): bool
    {
        return self::isUnstoppableBattle($action) || \in_array($action['action'], [
            TurnAction::CURSE->value,
            TurnAction::USE_SPELL->value,
            TurnAction::CURSED_ROOM->value,
            TurnAction::DUEL->value,
            TurnAction::DUEL_LOOT->value,
            TurnAction::SPRINT->value,
            TurnAction::RETREAT->value,
            TurnAction::LORD_KARAK->value,
        ], true);
    }

    #[Handler]
    public function performAction(PerformTurnAction $command, MessageContext $messageContext): void
    {
        if (!$this->playerId->equals($command->playerId)) {
            throw new NotYourTurnException();
        }

        // Special handling for battle actions (a Battle Arena duel is recorded the same way, even after the turn ended)
        if (\in_array($command->action, [TurnAction::FIGHT_MONSTER, TurnAction::DUEL], true)) {
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

        // For all other actions: the turn is open, the action may follow the last one and the moves suffice
        $this->assertCanPerform($command->action);

        $this->actions[] = [
            'action' => $command->action->value,
            'tileId' => $command->tileId?->toString(),
            'additionalData' => $command->additionalData,
            'performedAt' => $command->at->format(\DateTimeInterface::ATOM),
        ];
        if ($command->action === TurnAction::SPRINT) {
            $this->sprint = true;
        }

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

        // If turn end was deferred for item pickup, mark it
        if ($command->deferTurnEnd && $this->performedActionsCount >= $this->maxMoves()) {
            $this->pendingItemPickup = true;
        }

        // Only use action count to end turn if no battle occurred and turn end is not deferred (e.g. pickable item at destination)
        // Markul "Lightning Attack" spends all moves but the fight that follows decides how the turn ends
        $movesRunOut = $this->performedActionsCount >= $this->maxMoves() && $command->action !== TurnAction::LIGHTNING_ATTACK;
        if ((!$hasBattleInTurn && !$command->deferTurnEnd && $movesRunOut) || $command->action->isEndOfTurn()) {
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

    /**
     * Throws when the action may not be performed now, by the same rules performAction applies, without recording
     * it: a move changes the hero's position before it is recorded, so it is checked first (a refused move must leave
     * the hero where he stands).
     *
     * @throws TurnAlreadyEndedException
     * @throws InvalidTurnActionException
     */
    public function assertCanPerform(TurnAction $action): void
    {
        if ($this->endTime !== null) {
            throw new TurnAlreadyEndedException();
        }

        // The 4th move ended on a reward: only picking it up, ending the turn or the bookkeeping may follow
        if ($this->pendingItemPickup && !\in_array($action, [
            TurnAction::PICK_ITEM,
            TurnAction::END_TURN,
            TurnAction::CURSE,
            TurnAction::USE_SPELL,
            TurnAction::CURSED_ROOM,
            TurnAction::DUEL,
            TurnAction::DUEL_LOOT,
            TurnAction::RETREAT,
            TurnAction::LORD_KARAK,
        ], true)) {
            throw new InvalidTurnActionException($action, TurnAction::PICK_ITEM);
        }

        // Hanna "Sprint": the run goes over discovered tiles only
        if ($this->sprint && \in_array($action, [
            TurnAction::PICK_TILE,
            TurnAction::ROTATE_TILE,
            TurnAction::PLACE_TILE,
            TurnAction::DISCOVER_TILE,
        ], true)) {
            throw new InvalidTurnActionException($action, TurnAction::SPRINT);
        }

        // Check if the action is allowed based on turn action rules
        $lastAction = $this->getLastAction();
        if (!$action->isAllowedAfter($lastAction)) {
            throw new InvalidTurnActionException($action, $lastAction);
        }

        // No move beyond the turn's moves (4, or 8 for Hanna's "Sprint")
        if ($action->isIncreasesActionCounter() && $this->performedActionsCount + $action->moveCost() > $this->maxMoves()) {
            throw new InvalidTurnActionException($action, $lastAction);
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

        // A fight that ends the turn costs nothing (the turn is over anyway). A fight after which the turn goes on —
        // Swordsman "Unstoppable" — spends the step that led into it, like any move; a Lightning Attack fight was
        // paid with all 4 moves already
        if ($event->action === TurnAction::FIGHT_MONSTER) {
            $data = $event->additionalData ?? [];
            if (($data['unstoppable'] ?? false) === true && ($data['lightningAttack'] ?? false) !== true) {
                ++$this->performedActionsCount;
            }

            return;
        }

        if ($event->action->isIncreasesActionCounter()) {
            $this->performedActionsCount += $event->action->moveCost();
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

        // Check if there's an unplaced tile
        $field = $messageContext->dispatch(new GetField($command->gameId));
        $unplacedTile = $field->getUnplacedTile();
        if ($unplacedTile !== null && isset($unplacedTile['tileId']) && $unplacedTile['tileId'] !== null) {
            throw new UnplacedTileException('Cannot end turn with an unplaced tile. Please place the tile first.');
        }

        // Warrior "Reincarnation": the fallen hero must choose a healing fountain first (Field::reincarnate ends the turn)
        $player = $messageContext->dispatch(new GetPlayer($command->playerId));
        if ($player->isAwaitingReincarnation()) {
            throw new \DomainException('Choose a healing fountain to reincarnate before ending the turn');
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

    /**
     * Moves spent this turn (Oracle "Farseeing" reads it: the bonus applies only before the first one).
     */
    public function getPerformedActionsCount(): int
    {
        return $this->performedActionsCount;
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
     * Check if a battle that locked the rest of the turn occurred during this turn.
     *
     * Swordsman "Unstoppable" battles (a 6 on the dice) do not count: the turn goes on
     * and the regular action cap applies again.
     */
    public function hasBattleInTurn(): bool
    {
        $battleLocksTurn = false;
        foreach ($this->actions as $action) {
            if ($action['action'] === TurnAction::FIGHT_MONSTER->value && !self::isUnstoppableBattle($action)) {
                $battleLocksTurn = true;
            }
            // Valduk "Tireless": leaving the loot unlocks the rest of the turn again
            if ($action['action'] === TurnAction::TIRELESS->value) {
                $battleLocksTurn = false;
            }
        }

        return $battleLocksTurn;
    }

    /**
     * Moves this turn may spend: 4, or 8 for Hanna's "Sprint".
     */
    public function maxMoves(): int
    {
        return $this->sprint ? 8 : self::MAX_ACTIONS_PER_TURN;
    }

    /**
     * Hanna "Sprint" was declared this turn (no tile exploration).
     */
    public function isSprint(): bool
    {
        return $this->sprint;
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

    /**
     * The last action that constrains what may follow.
     * Unstoppable battles are transparent: the turn continues as if the fight had not interrupted it.
     */
    private function getLastAction(): ?TurnAction
    {
        for ($index = \count($this->actions) - 1; $index >= 0; --$index) {
            $action = $this->actions[$index];
            if (self::isBookkeepingAction($action)) {
                continue;
            }

            return TurnAction::from($action['action']);
        }

        return null;
    }

    private function endTurn(\DateTimeImmutable $at): void
    {
        $this->endTime = $at;
    }
}
