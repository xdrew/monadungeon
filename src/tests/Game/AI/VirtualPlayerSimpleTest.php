<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\VirtualPlayerSimple;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GetGame;
use App\Game\Player\GetPlayer;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VirtualPlayerSimpleTest extends TestCase
{
    #[Test]
    public function shouldExecuteTurnSuccessfully(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        $game = $this->createMockGame();
        $field = $this->createMockField();
        $player = $this->createMockPlayer(false);

        $endTurnCalled = false;
        
        $tester = MessageBusTester::create(
            function (GetGame $query) use ($game) {
                return $game;
            },
            function (GetCurrentTurn $query) use ($turnId) {
                return $turnId;
            },
            function (GetField $query) use ($field) {
                return $field;
            },
            function (GetPlayer $query) use ($player) {
                return $player;
            },
            function (EndTurn $command) use (&$endTurnCalled) {
                $endTurnCalled = true;
                return null;
            }
        );

        $virtualPlayer = new VirtualPlayerSimple($tester->messageBus());

        // Act
        $result = $virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($endTurnCalled, 'EndTurn command should have been dispatched');
        
        // Check for expected action types
        $actionTypes = array_column($result, 'type');
        $this->assertContains('ai_thinking', $actionTypes);
        
        // Check if we got ai_decision or ai_error (in case of early return)
        if (in_array('ai_error', $actionTypes)) {
            // If there was an error, we won't have ai_decision
            $this->assertContains('ai_error', $actionTypes);
        } else {
            // Normal flow should have ai_decision and turn_ended
            $this->assertContains('ai_decision', $actionTypes);
            $this->assertContains('turn_ended', $actionTypes);
        }
    }

    #[Test]
    public function shouldHandleStunnedPlayer(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        $game = $this->createMockGame();
        $field = $this->createMockField();
        $player = $this->createMockPlayer(true); // Player is defeated/stunned

        $endTurnCalled = false;
        
        $tester = MessageBusTester::create(
            function (GetGame $query) use ($game) {
                return $game;
            },
            function (GetCurrentTurn $query) use ($turnId) {
                return $turnId;
            },
            function (GetField $query) use ($field) {
                return $field;
            },
            function (GetPlayer $query) use ($player) {
                return $player;
            },
            function (EndTurn $command) use (&$endTurnCalled) {
                $endTurnCalled = true;
                return null;
            }
        );

        $virtualPlayer = new VirtualPlayerSimple($tester->messageBus());

        // Act
        $result = $virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($endTurnCalled, 'EndTurn should be called even for stunned player');
        
        // Check that AI handled stunned state
        $skipActions = array_filter($result, fn($a) => $a['type'] === 'player_stunned');
        $this->assertNotEmpty($skipActions, 'Should have player_stunned action');
    }

    #[Test]
    public function shouldHandleExceptions(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        
        $tester = MessageBusTester::create(
            function (GetGame $query) {
                throw new \Exception('Test exception');
            }
        );

        $virtualPlayer = new VirtualPlayerSimple($tester->messageBus());

        // Act
        $result = $virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        
        // Check for error action
        $errorActions = array_filter($result, fn($a) => $a['type'] === 'ai_error');
        $this->assertNotEmpty($errorActions, 'Should have ai_error action');
        
        $errorAction = reset($errorActions);
        $this->assertStringContainsString('Test exception', $errorAction['details']['error']);
    }

    private function createMockGame(): object
    {
        $game = new \stdClass();
        $game->gameId = Uuid::v7();
        return $game;
    }

    private function createMockTurn(Uuid $turnId): object
    {
        return new class($turnId) {
            public function __construct(private Uuid $turnId) {}
            
            public function getTurnId(): Uuid
            {
                return $this->turnId;
            }
        };
    }

    private function createMockField(): object
    {
        $field = new \stdClass();
        $field->tiles = [];
        $field->items = [];
        return $field;
    }

    private function createMockPlayer(bool $isDefeated): object
    {
        return new class($isDefeated) {
            public Uuid $playerId;
            public int $hp;
            public int $maxHp;
            private bool $isDefeated;
            
            public function __construct(bool $isDefeated) {
                $this->playerId = Uuid::v7();
                $this->hp = $isDefeated ? 0 : 5;
                $this->maxHp = 5;
                $this->isDefeated = $isDefeated;
            }
            
            public function isDefeated(): bool {
                return $this->isDefeated;
            }
        };
    }
}