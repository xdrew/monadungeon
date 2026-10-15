<?php

declare(strict_types=1);

namespace App\Tests\Game\Turn;

use App\Game\Field\Field;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\NextTurn;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Player\GetPlayer;
use App\Game\Player\Player;
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
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

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
    public function itCountsOnlyMovesAsPerformedActions(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);
        self::assertSame(0, $gameTurn->getPerformedActionsCount());

        // Oracle "Farseeing": exploring (pick + place) does not spend a move
        foreach ([TurnAction::PICK_TILE, TurnAction::PLACE_TILE] as $action) {
            $gameTurn->updateActionCounter(new TurnActionPerformed(
                turnId: $this->turnId,
                gameId: $this->gameId,
                playerId: $this->playerId,
                action: $action,
                performedAt: $this->fixedTime,
            ));
        }
        self::assertSame(0, $gameTurn->getPerformedActionsCount());

        $gameTurn->updateActionCounter(new TurnActionPerformed(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MOVE,
            performedAt: $this->fixedTime,
        ));
        self::assertSame(1, $gameTurn->getPerformedActionsCount());
    }

    #[Test]
    public function theCurseIsBookkeepingAfterAFight(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);
        $tester = MessageBusTester::create();
        $action = fn(TurnAction $action): PerformTurnAction => new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: $action,
            at: $this->fixedTime,
        );

        $tester->handle($gameTurn->performAction(...), $action(TurnAction::FIGHT_MONSTER));
        // Curse of Karak: accepted after the fight, does not count as a move
        $tester->handle($gameTurn->performAction(...), $action(TurnAction::CURSE));
        self::assertSame(['fight_monster', 'curse'], array_column($gameTurn->getActions(), 'action'));
        self::assertSame(0, $gameTurn->getPerformedActionsCount());
        self::assertFalse($gameTurn->isEnded());

        // The fight is still what happened last: moving on is rejected, ending the turn is fine
        try {
            $tester->handle($gameTurn->performAction(...), $action(TurnAction::MOVE));
            self::fail('A move after a fight must be rejected');
        } catch (InvalidTurnActionException) {
        }
        $tester->handle($gameTurn->performAction(...), $action(TurnAction::END_TURN));
        self::assertTrue($gameTurn->isEnded());
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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

        // End the turn first
        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            at: $this->fixedTime,
        );
        $tester = MessageBusTester::create(
            fn(GetGame $_query): Game => Game::create(new CreateGame($this->gameId), startMessageContext()),
            fn(GetField $_query): Field => Field::create(new GameCreated(gameId: $this->gameId, gameCreateTime: new \DateTimeImmutable()), startMessageContext()),
            fn(GetPlayer $_query): Player => Player::onPlayerAddedToGame(new PlayerAdded(gameId: $this->gameId, playerId: $this->playerId)),
        );
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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create(
            fn(GetGame $_query): Game => Game::create(new CreateGame($this->gameId), startMessageContext()),
            fn(GetField $_query): Field => Field::create(new GameCreated(gameId: $this->gameId, gameCreateTime: new \DateTimeImmutable()), startMessageContext()),
            fn(GetPlayer $_query): Player => Player::onPlayerAddedToGame(new PlayerAdded(gameId: $this->gameId, playerId: $this->playerId)),
        );
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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

        $otherPlayerId = Uuid::v7();
        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $otherPlayerId,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create(
            fn(GetGame $_query): Game => Game::create(new CreateGame($this->gameId), startMessageContext()),
            fn(GetField $_query): Field => Field::create(new GameCreated(gameId: $this->gameId, gameCreateTime: new \DateTimeImmutable()), startMessageContext()),
            fn(GetPlayer $_query): Player => Player::onPlayerAddedToGame(new PlayerAdded(gameId: $this->gameId, playerId: $this->playerId)),
        );

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            at: $this->fixedTime,
        );

        $tester = MessageBusTester::create(
            fn(GetGame $_query): Game => Game::create(new CreateGame($this->gameId), startMessageContext()),
            fn(GetField $_query): Field => Field::create(new GameCreated(gameId: $this->gameId, gameCreateTime: new \DateTimeImmutable()), startMessageContext()),
            fn(GetPlayer $_query): Player => Player::onPlayerAddedToGame(new PlayerAdded(gameId: $this->gameId, playerId: $this->playerId)),
        );

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

        // End the turn
        $endTurn = new EndTurn(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            at: $this->fixedTime,
        );
        $tester = MessageBusTester::create(
            fn(GetGame $_query): Game => Game::create(new CreateGame($this->gameId), startMessageContext()),
            fn(GetField $_query): Field => Field::create(new GameCreated(gameId: $this->gameId, gameCreateTime: new \DateTimeImmutable()), startMessageContext()),
            fn(GetPlayer $_query): Player => Player::onPlayerAddedToGame(new PlayerAdded(gameId: $this->gameId, playerId: $this->playerId)),
        );
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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);

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

    #[Test]
    public function itAppliesTheActionCapAgainAfterAnUnstoppableBattle(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);
        $tester = MessageBusTester::create();

        // Swordsman "Unstoppable": the fight is recorded, but the turn goes on as if nothing had happened
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::FIGHT_MONSTER, [
            'monster' => ['name' => 'giant_rat', 'hp' => 5],
            'result' => 'LOSE',
            'unstoppable' => true,
        ]));
        self::assertFalse($gameTurn->hasBattleInTurn(), 'An unstoppable battle does not lock the turn');

        // Moving again is allowed and counted
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));
        $tester->handle($gameTurn->updateActionCounter(...), $this->performed(TurnAction::MOVE));
        $tester->handle($gameTurn->updateActionCounter(...), $this->performed(TurnAction::MOVE));
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));
        $tester->handle($gameTurn->updateActionCounter(...), $this->performed(TurnAction::MOVE));
        self::assertFalse($gameTurn->isEnded());

        // The 4th move ends the turn exactly like in a turn without any battle
        $tester->handle($gameTurn->updateActionCounter(...), $this->performed(TurnAction::MOVE));
        [, $messages] = $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));

        self::assertTrue($gameTurn->isEnded());
        self::assertCount(1, array_filter($messages, static fn(object $message): bool => $message instanceof TurnEnded));
    }

    #[Test]
    public function itStillLocksTheTurnAfterARegularBattle(): void
    {
        $startTurn = new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
        [$gameTurn] = handle(GameTurn::start(...), $startTurn);
        $tester = MessageBusTester::create();

        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::FIGHT_MONSTER, [
            'monster' => ['name' => 'giant_rat', 'hp' => 5],
            'result' => 'LOSE',
            'unstoppable' => false,
        ]));
        self::assertTrue($gameTurn->hasBattleInTurn());

        $this->expectException(InvalidTurnActionException::class);
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));
    }

    #[Test]
    public function aMagicSwapSpendsAllFourMovesAndEndsTheTurn(): void
    {
        [$gameTurn] = handle(GameTurn::start(...), $this->startTurn());
        // Route TurnActionPerformed to the counter like the real bus does
        $tester = MessageBusTester::create(static fn(TurnActionPerformed $event) => $gameTurn->updateActionCounter($event));

        [, $messages] = $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MAGIC_SWAP, ['ability' => 'magic_swap']));

        self::assertTrue($gameTurn->isEnded(), 'Magic Swap costs 4 moves: the turn ends right away');
        self::assertCount(1, array_filter($messages, static fn(object $message): bool => $message instanceof TurnEnded));
    }

    #[Test]
    public function aMagicSwapOntoAnItemTileLeavesTheRewardToPickUp(): void
    {
        [$gameTurn] = handle(GameTurn::start(...), $this->startTurn());
        $tester = MessageBusTester::create(static fn(TurnActionPerformed $event) => $gameTurn->updateActionCounter($event));

        [, $messages] = $tester->handle($gameTurn->performAction(...), new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: TurnAction::MAGIC_SWAP,
            additionalData: ['ability' => 'magic_swap'],
            at: $this->fixedTime,
            deferTurnEnd: true,
        ));

        self::assertFalse($gameTurn->isEnded(), 'The reward on the new tile may still be picked up');
        self::assertCount(0, array_filter($messages, static fn(object $message): bool => $message instanceof TurnEnded));

        // Picking the item is still allowed, nothing else is
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::PICK_ITEM));
        self::assertSame(TurnAction::PICK_ITEM->value, $gameTurn->getActions()[1]['action']);
    }

    #[Test]
    public function aMagicSwapIsOnlyAllowedAtTheBeginningOfTheTurn(): void
    {
        [$gameTurn] = handle(GameTurn::start(...), $this->startTurn());
        $tester = MessageBusTester::create();

        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));

        $this->expectException(InvalidTurnActionException::class);
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MAGIC_SWAP, ['ability' => 'magic_swap']));
    }

    #[Test]
    public function aRegularMoveStillCostsOneMove(): void
    {
        [$gameTurn] = handle(GameTurn::start(...), $this->startTurn());
        $tester = MessageBusTester::create(static fn(TurnActionPerformed $event) => $gameTurn->updateActionCounter($event));

        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));
        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));
        self::assertFalse($gameTurn->isEnded());

        $tester->handle($gameTurn->performAction(...), $this->action(TurnAction::MOVE));
        self::assertTrue($gameTurn->isEnded(), 'The 4th move ends the turn');
    }

    // Karak: Regent — spells cost no move and the Regent bookkeeping never constrains the turn

    #[Test]
    public function aSpellMayOpenTheTurnAndBeCastAgain(): void
    {
        $turn = $this->regentTurn();
        $this->perform($turn, TurnAction::USE_SPELL);
        $this->perform($turn, TurnAction::USE_SPELL);
        $this->perform($turn, TurnAction::MOVE);
        $this->perform($turn, TurnAction::USE_SPELL);
        $this->perform($turn, TurnAction::MOVE);

        self::assertSame(2, $turn->getPerformedActionsCount(), 'Only the moves count');
        self::assertFalse($turn->isEnded());
    }

    #[Test]
    public function aSpellIsNotCastWhileATileIsInHand(): void
    {
        $turn = $this->regentTurn();
        $this->perform($turn, TurnAction::PICK_TILE);

        $this->expectException(InvalidTurnActionException::class);
        $this->perform($turn, TurnAction::USE_SPELL);
    }

    #[Test]
    public function regentBookkeepingIsTransparent(): void
    {
        $turn = $this->regentTurn();
        $this->perform($turn, TurnAction::PICK_TILE);
        foreach ([TurnAction::CURSED_ROOM, TurnAction::DUEL, TurnAction::DUEL_LOOT] as $action) {
            $this->perform($turn, $action);
        }
        // The last constraining action is still PICK_TILE
        $this->perform($turn, TurnAction::PLACE_TILE);

        self::assertSame(0, $turn->getPerformedActionsCount());
    }

    #[Test]
    public function aDuelIsRecordedEvenAfterTheTurnEnded(): void
    {
        $turn = $this->regentTurn();
        $this->perform($turn, TurnAction::MOVE);
        $this->perform($turn, TurnAction::END_TURN);
        self::assertTrue($turn->isEnded());

        $this->perform($turn, TurnAction::DUEL);

        $actions = $turn->getActions();
        self::assertSame('duel', $actions[\count($actions) - 1]['action']);
    }

    // Karak: Regent heroes — Sprint, Listening, Tireless, Lightning Attack and the retreat

    #[Test]
    public function aSprintAllowsEightMovesAndNoExploration(): void
    {
        [$turn, $tester] = $this->countedTurn();
        $tester->handle($turn->performAction(...), $this->action(TurnAction::SPRINT));
        self::assertTrue($turn->isSprint());
        self::assertSame(8, $turn->maxMoves());

        for ($move = 1; $move <= 7; ++$move) {
            $tester->handle($turn->performAction(...), $this->action(TurnAction::MOVE));
        }
        self::assertFalse($turn->isEnded());

        try {
            $tester->handle($turn->performAction(...), $this->action(TurnAction::PICK_TILE));
            self::fail('No tile exploration while sprinting');
        } catch (InvalidTurnActionException) {
        }

        $tester->handle($turn->performAction(...), $this->action(TurnAction::MOVE));
        self::assertTrue($turn->isEnded(), 'The 8th move ends the sprint');
    }

    #[Test]
    public function aSprintOnlyOpensTheTurn(): void
    {
        [$turn, $tester] = $this->countedTurn();
        $tester->handle($turn->performAction(...), $this->action(TurnAction::MOVE));
        self::assertSame(4, $turn->maxMoves());

        $this->expectException(InvalidTurnActionException::class);
        $tester->handle($turn->performAction(...), $this->action(TurnAction::SPRINT));
    }

    #[Test]
    public function listeningFollowsATilePlacementAndCostsAMove(): void
    {
        [$turn, $tester] = $this->countedTurn();
        foreach ([TurnAction::PICK_TILE, TurnAction::PLACE_TILE, TurnAction::LISTEN, TurnAction::PICK_TILE, TurnAction::PLACE_TILE, TurnAction::LISTEN] as $action) {
            $tester->handle($turn->performAction(...), $this->action($action));
        }
        self::assertSame(2, $turn->getPerformedActionsCount(), 'Each listening costs one move');

        $tester->handle($turn->performAction(...), $this->action(TurnAction::MOVE));
        $this->expectException(InvalidTurnActionException::class);
        $tester->handle($turn->performAction(...), $this->action(TurnAction::LISTEN));
    }

    #[Test]
    public function tirelessReopensTheTurnAfterAWonFight(): void
    {
        [$turn, $tester] = $this->countedTurn();
        $tester->handle($turn->performAction(...), $this->action(TurnAction::MOVE));
        $tester->handle($turn->performAction(...), $this->action(TurnAction::FIGHT_MONSTER, ['result' => 'WIN']));
        self::assertTrue($turn->hasBattleInTurn());

        $tester->handle($turn->performAction(...), $this->action(TurnAction::TIRELESS));
        self::assertFalse($turn->hasBattleInTurn());
        self::assertSame(2, $turn->getPerformedActionsCount(), 'The step into the fight is paid');

        $tester->handle($turn->performAction(...), $this->action(TurnAction::MOVE));
        self::assertFalse($turn->isEnded());
        $tester->handle($turn->performAction(...), $this->action(TurnAction::MOVE));
        self::assertTrue($turn->isEnded(), 'The moves run out as usual');
    }

    #[Test]
    public function theLootLeftByTirelessCannotBePickedUp(): void
    {
        [$turn, $tester] = $this->countedTurn();
        $tester->handle($turn->performAction(...), $this->action(TurnAction::FIGHT_MONSTER, ['result' => 'WIN']));
        $tester->handle($turn->performAction(...), $this->action(TurnAction::TIRELESS));

        $this->expectException(InvalidTurnActionException::class);
        $tester->handle($turn->performAction(...), $this->action(TurnAction::PICK_ITEM));
    }

    #[Test]
    public function tirelessOnlyFollowsAFight(): void
    {
        [$turn, $tester] = $this->countedTurn();
        $tester->handle($turn->performAction(...), $this->action(TurnAction::MOVE));

        $this->expectException(InvalidTurnActionException::class);
        $tester->handle($turn->performAction(...), $this->action(TurnAction::TIRELESS));
    }

    #[Test]
    public function aLightningAttackLeavesTheTurnToTheFight(): void
    {
        [$turn, $tester] = $this->countedTurn();
        $tester->handle($turn->performAction(...), $this->action(TurnAction::LIGHTNING_ATTACK));
        self::assertSame(4, $turn->getPerformedActionsCount());
        self::assertFalse($turn->isEnded(), 'The fight that follows decides');

        $tester->handle($turn->performAction(...), $this->action(TurnAction::FIGHT_MONSTER, ['result' => 'LOSE']));
        $tester->handle($turn->performAction(...), $this->action(TurnAction::RETREAT));
        self::assertSame(4, $turn->getPerformedActionsCount(), 'The retreat is not a move');

        $tester->handle($turn->performAction(...), $this->action(TurnAction::END_TURN));
        self::assertTrue($turn->isEnded());
    }

    #[Test]
    public function aLightningAttackOnlyOpensTheTurn(): void
    {
        [$turn, $tester] = $this->countedTurn();
        $tester->handle($turn->performAction(...), $this->action(TurnAction::MOVE));

        $this->expectException(InvalidTurnActionException::class);
        $tester->handle($turn->performAction(...), $this->action(TurnAction::LIGHTNING_ATTACK));
    }

    private function startTurn(): StartTurn
    {
        return new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        );
    }

    private function action(TurnAction $action, ?array $additionalData = null): PerformTurnAction
    {
        return new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: $action,
            additionalData: $additionalData,
            at: $this->fixedTime,
        );
    }

    private function performed(TurnAction $action): TurnActionPerformed
    {
        return new TurnActionPerformed(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: $action,
            performedAt: $this->fixedTime,
        );
    }

    /**
     * A started turn whose TurnActionPerformed events reach the move counter, like on the real bus.
     *
     * @return array{GameTurn, MessageBusTester}
     */
    private function countedTurn(): array
    {
        $turn = $this->regentTurn();

        return [$turn, MessageBusTester::create(static fn(TurnActionPerformed $event) => $turn->updateActionCounter($event))];
    }

    private function regentTurn(): GameTurn
    {
        [$turn] = handle(GameTurn::start(...), new StartTurn(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnNumber: 1,
            turnId: $this->turnId,
            at: $this->fixedTime,
        ));

        return $turn;
    }

    private function perform(GameTurn $turn, TurnAction $action): void
    {
        handle($turn->performAction(...), new PerformTurnAction(
            turnId: $this->turnId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            action: $action,
            at: $this->fixedTime,
        ));
        $turn->updateActionCounter(new TurnActionPerformed($this->turnId, $this->gameId, $this->playerId, $action, $this->fixedTime));
    }
}
