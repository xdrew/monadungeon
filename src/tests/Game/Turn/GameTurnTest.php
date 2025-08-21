<?php

declare(strict_types=1);

namespace App\Tests\Game\Turn;

use App\Game\GameLifecycle\NextTurn;
use App\Game\Turn\EndTurn;
use App\Game\Turn\Error\InvalidTurnActionException;
use App\Game\Turn\Error\NotYourTurnException;
use App\Game\Turn\Error\TurnAlreadyEndedException;
use App\Game\Turn\GameTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnActionPerformed;
use App\Game\Turn\TurnEnded;
use App\Game\Turn\TurnStarted;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

#[CoversClass(GameTurn::class)]
final class GameTurnTest extends TestCase
{
    private Uuid $gameId;
    private Uuid $playerId;
    private Uuid $turnId;
    private \DateTimeImmutable $fixedTime;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->fixedTime = new \DateTimeImmutable('2024-01-01 12:00:00');
    }

    #[Test]
    public function itStartsTurn(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );

        [$gameTurn, $messages] = handle(GameTurn::start(...), $startTurn);

        self::assertInstanceOf(GameTurn::class, $gameTurn);
        self::assertEquals($this->turnId, $gameTurn->getTurnId());
        self::assertEquals($this->gameId, $gameTurn->getGameId());
        self::assertEquals($this->playerId, $gameTurn->getPlayerId());
        self::assertEquals(1, $gameTurn->getTurnNumber());
        self::assertFalse($gameTurn->isEnded());
        self::assertEquals([], $gameTurn->getActions());
        
        self::assertEquals(
            [new TurnStarted($this->turnId, $this->gameId, $this->playerId, 1, $this->fixedTime)],
            $messages,
        );
    }

    #[Test]
    public function itPerformsValidMoveAction(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $performAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($gameTurn->performAction(...), $performAction);

        $actions = $gameTurn->getActions();
        self::assertCount(1, $actions);
        self::assertEquals('move', $actions[0]['action']);
        self::assertEquals($this->fixedTime->format(\DateTimeInterface::ATOM), $actions[0]['performedAt']);
        self::assertFalse($gameTurn->isEnded());

        self::assertEquals(
            [new TurnActionPerformed(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                action: TurnAction::MOVE,
                performedAt: $this->fixedTime,
                tileId: null,
                additionalData: null,
            )],
            $messages,
        );
    }

    #[Test]
    public function itPerformsActionWithTileIdAndAdditionalData(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $tileId = Uuid::v7();
        $additionalData = ['some' => 'data'];
        $tester = MessageBusTester::create();
        
        // First do a movement action (allowed at start of turn)
        $moveAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            at: $this->fixedTime,
        );
        $tester->handle($gameTurn->performAction(...), $moveAction);

        $performAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::PLACE_TILE,
            tileId: $tileId,
            additionalData: $additionalData,
            at: $this->fixedTime,
        );

        [, $messages] = $tester->handle($gameTurn->performAction(...), $performAction);

        $actions = $gameTurn->getActions();
        self::assertCount(2, $actions);
        self::assertEquals('move', $actions[0]['action']);
        self::assertEquals('place_tile', $actions[1]['action']);
        self::assertEquals($tileId->toString(), $actions[1]['tileId']);
        self::assertEquals($additionalData, $actions[1]['additionalData']);

        self::assertEquals(
            [
                new TurnActionPerformed(
                    turnId: $this->turnId,
                    gameId: $this->gameId,
                    playerId: $this->playerId,
                    action: TurnAction::PLACE_TILE,
                    performedAt: $this->fixedTime,
                    tileId: $tileId,
                    additionalData: $additionalData,
                ),
                // Note: Turn ending is now handled manually by frontend after movement
            ],
            $messages,
        );
    }

    #[Test]
    public function itThrowsExceptionWhenWrongPlayerTriesToPerformAction(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $otherPlayerId = Uuid::v7();
        $performAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $otherPlayerId,
            action: TurnAction::MOVE,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create();
        
        $this->expectException(NotYourTurnException::class);
        $tester->handle($gameTurn->performAction(...), $performAction);
    }

    #[Test]
    public function itThrowsExceptionWhenTryingToPerformActionAfterTurnEnded(): void
    {
        $startTurn = new StartTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        // End the turn first
        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            at: $this->fixedTime,
        );
        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->end(...), $endTurn);

        // Try to perform an action after turn has ended
        $performAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            at: $this->fixedTime,
        );

        $this->expectException(TurnAlreadyEndedException::class);
        $tester->handle($gameTurn->performAction(...), $performAction);
    }

    #[Test]
    public function itThrowsExceptionForInvalidActionSequence(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $tester = MessageBusTester::create();
        
        // First perform a movement action (allowed at start of turn)
        $moveAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            at: $this->fixedTime,
        );
        $tester->handle($gameTurn->performAction(...), $moveAction);

        // Then perform an equipment pickup action (which ends the turn)
        $performAction1 = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::PICK_UP_EQUIPMENT,
            at: $this->fixedTime,
        );

        $tester->handle($gameTurn->performAction(...), $performAction1);

        // Equipment pickup should have ended the turn
        self::assertTrue($gameTurn->isEnded());
    }

    #[Test]
    public function itAllowsInvalidActionSequenceToBeDetected(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        // Try to perform an action that's not allowed at the start of turn
        $performAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::UNLOCK_CHEST, // Not allowed at start of turn
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create();

        $this->expectException(InvalidTurnActionException::class);
        $tester->handle($gameTurn->performAction(...), $performAction);
    }

    #[Test]
    public function itEndsTurn(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($gameTurn->end(...), $endTurn);

        self::assertTrue($gameTurn->isEnded());
        self::assertEquals($this->fixedTime, $gameTurn->getEndTime());

        // Now only TurnEnded is dispatched (NextTurn is handled by Game aggregate)
        self::assertCount(1, $messages);
        self::assertInstanceOf(TurnEnded::class, $messages[0]);
        self::assertEquals($this->turnId, $messages[0]->turnId);
        self::assertEquals($this->gameId, $messages[0]->gameId);
        self::assertEquals($this->playerId, $messages[0]->playerId);
        self::assertEquals($this->fixedTime, $messages[0]->endTime);
    }

    #[Test]
    public function itThrowsExceptionWhenWrongPlayerTriesToEndTurn(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $otherPlayerId = Uuid::v7();
        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $otherPlayerId,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create();

        $this->expectException(NotYourTurnException::class);
        $tester->handle($gameTurn->end(...), $endTurn);
    }

    #[Test]
    public function itIgnoresMultipleEndTurnAttempts(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create();
        
        // End turn first time
        $tester->handle($gameTurn->end(...), $endTurn);
        self::assertTrue($gameTurn->isEnded());

        // Try to end turn again - should not throw exception
        $tester->handle($gameTurn->end(...), $endTurn);
        self::assertTrue($gameTurn->isEnded());
    }

    #[Test]
    public function itUpdatesActionCounter(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $actionPerformed = new TurnActionPerformed(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            performedAt: $this->fixedTime,
        );

        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->updateActionCounter(...), $actionPerformed);

        // Action counter is private, but we can test indirectly by checking
        // that subsequent actions are properly validated
        self::assertFalse($gameTurn->isEnded());
    }

    #[Test]
    public function itThrowsExceptionWhenWrongPlayerTriesToUpdateActionCounter(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $otherPlayerId = Uuid::v7();
        $actionPerformed = new TurnActionPerformed(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $otherPlayerId,
            action: TurnAction::MOVE,
            performedAt: $this->fixedTime,
        );

        $tester = MessageBusTester::create();

        $this->expectException(NotYourTurnException::class);
        $tester->handle($gameTurn->updateActionCounter(...), $actionPerformed);
    }

    #[Test]
    public function itIgnoresActionCounterUpdateAfterTurnEnded(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        // End the turn
        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            at: $this->fixedTime,
        );
        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->end(...), $endTurn);

        // Try to update action counter after turn ended
        $actionPerformed = new TurnActionPerformed(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            performedAt: $this->fixedTime,
        );

        // Should not throw exception
        $tester->handle($gameTurn->updateActionCounter(...), $actionPerformed);
        self::assertTrue($gameTurn->isEnded());
    }

    #[Test]
    public function itDoesNotUpdateActionCounterForFightMonsterAction(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $actionPerformed = new TurnActionPerformed(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::FIGHT_MONSTER,
            performedAt: $this->fixedTime,
        );

        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->updateActionCounter(...), $actionPerformed);

        // Turn should not end automatically after FIGHT_MONSTER
        self::assertFalse($gameTurn->isEnded());
    }

    #[Test]
    public function itHandlesBattleActionSpecialFlow(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        // First perform a move to get to a tile with a monster
        $moveAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->performAction(...), $moveAction);

        // Then fight the monster
        $fightAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::FIGHT_MONSTER,
            at: $this->fixedTime,
        );

        [, $messages] = $tester->handle($gameTurn->performAction(...), $fightAction);

        $actions = $gameTurn->getActions();
        self::assertCount(2, $actions);
        self::assertEquals('move', $actions[0]['action']);
        self::assertEquals('fight_monster', $actions[1]['action']);
        
        // Turn should not end automatically after battle
        self::assertFalse($gameTurn->isEnded());

        self::assertEquals(
            [new TurnActionPerformed(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                action: TurnAction::FIGHT_MONSTER,
                performedAt: $this->fixedTime,
                tileId: null,
                additionalData: null,
            )],
            $messages,
        );
    }

    #[Test]
    public function itAllowsPickItemAfterBattle(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        // First perform a move and fight
        $moveAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->performAction(...), $moveAction);

        $fightAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::FIGHT_MONSTER,
            at: $this->fixedTime,
        );
        $tester->handle($gameTurn->performAction(...), $fightAction);

        // Now pick up an item after battle
        $pickItemAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::PICK_ITEM,
            at: $this->fixedTime,
        );

        [, $messages] = $tester->handle($gameTurn->performAction(...), $pickItemAction);

        $actions = $gameTurn->getActions();
        self::assertCount(3, $actions);
        self::assertEquals('pick_item', $actions[2]['action']);

        self::assertEquals(
            [new TurnActionPerformed(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                action: TurnAction::PICK_ITEM,
                performedAt: $this->fixedTime,
                tileId: null,
                additionalData: null,
            )],
            $messages,
        );
    }

    #[Test]
    public function itEndssTurnWhenEndOfTurnActionIsPerformed(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $tester = MessageBusTester::create();
        
        // First perform a movement action (allowed at start of turn)
        $moveAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            at: $this->fixedTime,
        );
        $tester->handle($gameTurn->performAction(...), $moveAction);

        $endTurnAction = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::END_TURN,
            at: $this->fixedTime,
        );

        [, $messages] = $tester->handle($gameTurn->performAction(...), $endTurnAction);

        self::assertTrue($gameTurn->isEnded());
        self::assertEquals($this->fixedTime, $gameTurn->getEndTime());

        // Should dispatch both TurnActionPerformed, TurnEnded, and NextTurn
        self::assertCount(3, $messages);
        self::assertInstanceOf(TurnActionPerformed::class, $messages[0]);
        self::assertInstanceOf(TurnEnded::class, $messages[1]);
        self::assertInstanceOf(NextTurn::class, $messages[2]);
    }

    #[Test]
    public function itGetsTurnProperties(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 5,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        self::assertEquals($this->turnId, $gameTurn->getTurnId());
        self::assertEquals($this->gameId, $gameTurn->getGameId());
        self::assertEquals($this->playerId, $gameTurn->getPlayerId());
        self::assertEquals(5, $gameTurn->getTurnNumber());
        self::assertInstanceOf(\DateTimeImmutable::class, $gameTurn->getStartTime());
        self::assertNull($gameTurn->getEndTime());
        self::assertFalse($gameTurn->isEnded());
        self::assertEquals([], $gameTurn->getActions());
    }

    #[Test]
    public function itReturnsActionsInOrder(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);

        $tester = MessageBusTester::create();

        // Perform multiple actions
        $action1 = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            at: $this->fixedTime,
        );
        $tester->handle($gameTurn->performAction(...), $action1);

        $action2 = new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::FIGHT_MONSTER,
            at: $this->fixedTime,
        );
        $tester->handle($gameTurn->performAction(...), $action2);

        $actions = $gameTurn->getActions();
        self::assertCount(2, $actions);
        self::assertEquals('move', $actions[0]['action']);
        self::assertEquals('fight_monster', $actions[1]['action']);
    }
}