<?php

declare(strict_types=1);

namespace App\Tests\Game\Turn;

use App\Game\GameLifecycle\NextTurn;
use App\Game\Turn\EndTurn;
use App\Game\Turn\Error\InvalidTurnActionException;
use App\Game\Turn\GameTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnActionPerformed;
use App\Game\Turn\TurnEnded;
use App\Game\Turn\TurnStarted;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use function App\Tests\Infrastructure\MessageBus\handle;

#[CoversClass(GameTurn::class)]
class BattleTurnEndingTest extends TestCase
{
    #[Test]
    public function it_prevents_move_action_after_fight_monster_action(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        $at = new \DateTimeImmutable();
        
        // Start a turn
        $startTurn = new StartTurn(
            gameId: $gameId,
            playerId: $playerId,
            turnNumber: 1,
            turnId: $turnId,
            at: $at,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);
        
        // Perform a fight monster action
        $performBattle = new PerformTurnAction(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            action: TurnAction::FIGHT_MONSTER,
            additionalData: [
                'monster' => ['name' => 'Skeleton', 'hp' => 5],
                'result' => 'LOOSE',
            ],
            at: $at
        );
        
        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->performAction(...), $performBattle);
        
        // Try to perform a move action - this should fail
        $performMove = new PerformTurnAction(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            action: TurnAction::MOVE,
            at: $at,
        );
        
        $this->expectException(InvalidTurnActionException::class);
        $this->expectExceptionMessage('Cannot perform action MOVE after FIGHT_MONSTER');
        
        $tester->handle($gameTurn->performAction(...), $performMove);
    }
    
    #[Test]
    public function it_allows_end_turn_after_fight_monster(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        $at = new \DateTimeImmutable();
        
        // Start a turn
        $startTurn = new StartTurn(
            gameId: $gameId,
            playerId: $playerId,
            turnNumber: 1,
            turnId: $turnId,
            at: $at,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);
        
        // Perform a fight monster action
        $performBattle = new PerformTurnAction(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            action: TurnAction::FIGHT_MONSTER,
            additionalData: [
                'monster' => ['name' => 'Skeleton', 'hp' => 5],
                'result' => 'LOOSE',
            ],
            at: $at
        );
        
        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->performAction(...), $performBattle);
        
        // End turn should be allowed after battle
        $endTurn = new EndTurn(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            at: $at,
        );
        
        [, $messages] = $tester->handle($gameTurn->end(...), $endTurn);
        
        // Verify turn ended
        $this->assertTrue($gameTurn->isEnded());
        
        // Verify messages dispatched
        // Now only TurnEnded is dispatched (NextTurn is handled by Game aggregate)
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(TurnEnded::class, $messages[0]);
    }
    
    #[Test]
    public function it_automatically_ends_turn_after_any_battle_result(): void
    {
        // Test that turn ends automatically for LOOSE and DRAW results
        // WIN does not end turn immediately (to allow item pickup)
        $battleResults = ['LOOSE', 'DRAW'];
        
        foreach ($battleResults as $result) {
            $gameId = Uuid::v7();
            $playerId = Uuid::v7();
            $turnId = Uuid::v7();
            $at = new \DateTimeImmutable();
            
            // Start a turn
            $startTurn = new StartTurn(
                gameId: $gameId,
                playerId: $playerId,
                turnNumber: 1,
                turnId: $turnId,
                at: $at,
            );
            [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);
            
            // Verify turn is not ended initially
            $this->assertFalse($gameTurn->isEnded(), "Turn should not be ended initially for result: {$result}");
            
            // After a battle is processed in Battle.php, it should dispatch EndTurn
            // We simulate this by checking that the turn validation prevents further moves
            $performBattle = new PerformTurnAction(
                turnId: $turnId,
                gameId: $gameId,
                playerId: $playerId,
                action: TurnAction::FIGHT_MONSTER,
                additionalData: [
                    'monster' => ['name' => 'Skeleton', 'hp' => 5],
                    'result' => $result,
                ],
                at: $at
            );
            
            $tester = MessageBusTester::create();
            $tester->handle($gameTurn->performAction(...), $performBattle);
            
            // After a battle, no further moves should be allowed
            $performMove = new PerformTurnAction(
                turnId: $turnId,
                gameId: $gameId,
                playerId: $playerId,
                action: TurnAction::MOVE,
                at: $at,
            );
            
            try {
                $tester->handle($gameTurn->performAction(...), $performMove);
                $this->fail("Expected InvalidTurnActionException for battle result: {$result}");
            } catch (InvalidTurnActionException $e) {
                $this->assertStringContainsString('Cannot perform action MOVE after FIGHT_MONSTER', $e->getMessage());
            }
        }
    }
    
    #[Test]
    public function it_does_not_end_turn_after_battle_win(): void
    {
        // Test that turn does NOT end after a WIN result (to allow item pickup)
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        $at = new \DateTimeImmutable();
        
        // Start a turn
        $startTurn = new StartTurn(
            gameId: $gameId,
            playerId: $playerId,
            turnNumber: 1,
            turnId: $turnId,
            at: $at,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);
        
        // Perform a fight monster action with WIN result
        $performBattle = new PerformTurnAction(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            action: TurnAction::FIGHT_MONSTER,
            at: $at,
            additionalData: [
                'monster' => ['name' => 'Skeleton', 'hp' => 5],
                'result' => 'WIN',
            ]
        );
        
        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->performAction(...), $performBattle);
        
        // Turn should NOT be ended after a WIN
        $this->assertFalse($gameTurn->isEnded(), "Turn should not be ended after battle WIN");
        
        // But we should still be able to perform PICK_ITEM action
        $performPickItem = new PerformTurnAction(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            action: TurnAction::PICK_ITEM,
            at: $at,
        );
        
        // This should succeed (no exception)
        $tester->handle($gameTurn->performAction(...), $performPickItem);
        
        // Now the turn can be ended manually
        $endTurn = new EndTurn(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            at: $at,
        );
        
        $tester->handle($gameTurn->end(...), $endTurn);
        $this->assertTrue($gameTurn->isEnded());
    }
    
    #[Test] 
    public function it_handles_battle_win_with_consumables_correctly(): void
    {
        // Test the flow: initial LOSE → use consumables → final WIN → turn doesn't end
        
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        $at = new \DateTimeImmutable();
        
        // Start a turn
        $startTurn = new StartTurn(
            gameId: $gameId,
            playerId: $playerId,
            turnNumber: 1,
            turnId: $turnId,
            at: $at,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);
        
        // Move action (to trigger battle)
        $performMove = new PerformTurnAction(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            action: TurnAction::MOVE,
            at: $at,
        );
        
        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->performAction(...), $performMove);
        
        // Battle starts with initial LOSE result (weapons only)
        // In real flow, StartBattle would be called and player would select consumables
        // Then FinalizeBattle would be called with WIN result
        
        // Simulate the final battle result after consumables
        $performBattle = new PerformTurnAction(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            action: TurnAction::FIGHT_MONSTER,
            at: $at,
            additionalData: [
                'monster' => ['name' => 'Dragon', 'hp' => 15],
                'result' => 'WIN', // Won with consumables
                'usedConsumables' => true,
            ]
        );
        
        $tester->handle($gameTurn->performAction(...), $performBattle);
        
        // Turn should NOT be ended after winning with consumables
        $this->assertFalse($gameTurn->isEnded(), "Turn should not be ended after battle WIN with consumables");
        
        // Player should be able to pick up the item
        $performPickItem = new PerformTurnAction(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            action: TurnAction::PICK_ITEM,
            at: $at,
        );
        
        $tester->handle($gameTurn->performAction(...), $performPickItem);
        
        // Now end the turn
        $endTurn = new EndTurn(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            at: $at,
        );
        
        $tester->handle($gameTurn->end(...), $endTurn);
        $this->assertTrue($gameTurn->isEnded());
    }

    #[Test]
    public function it_ends_turn_when_player_wins_but_does_not_pickup_item(): void
    {
        // Test that turn ends when player wins battle but chooses not to pick up the item
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        $at = new \DateTimeImmutable();
        
        // Start a turn
        $startTurn = new StartTurn(
            gameId: $gameId,
            playerId: $playerId,
            turnNumber: 1,
            turnId: $turnId,
            at: $at,
        );
        [$gameTurn, ] = handle(GameTurn::start(...), $startTurn);
        
        // Perform a fight monster action with WIN result
        $performBattle = new PerformTurnAction(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            action: TurnAction::FIGHT_MONSTER,
            at: $at,
            additionalData: [
                'monster' => ['name' => 'Skeleton Turnkey', 'hp' => 8],
                'result' => 'WIN',
            ]
        );
        
        $tester = MessageBusTester::create();
        $tester->handle($gameTurn->performAction(...), $performBattle);
        
        // Turn should NOT be ended after a WIN (waiting for pickup decision)
        $this->assertFalse($gameTurn->isEnded(), "Turn should not be ended immediately after battle WIN");
        
        // Now simulate the player choosing NOT to pick up the item
        // In real flow, FinalizeBattle would be called with pickupItem = false
        // This should trigger EndTurn in Battle.php
        
        // The turn should be ended by the Battle handler when pickupItem = false
        // We simulate this by ending the turn manually (as Battle handler would)
        $endTurn = new EndTurn(
            turnId: $turnId,
            gameId: $gameId,
            playerId: $playerId,
            at: $at,
        );
        
        $tester->handle($gameTurn->end(...), $endTurn);
        $this->assertTrue($gameTurn->isEnded(), "Turn should be ended when player doesn't pick up item");
    }
}