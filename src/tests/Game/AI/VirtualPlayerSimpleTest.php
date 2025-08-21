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
use Telephantast\MessageBus\MessageBus;

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
        $turn = $this->createMockTurn($turnId);
        $field = $this->createMockField();
        $player = $this->createMockPlayer(false);

        $this->messageBus->expects($this->exactly(5))
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($game, $turn, $field, $player) {
                return match (get_class($command)) {
                    GetGame::class => $game,
                    GetCurrentTurn::class => $turn,
                    GetField::class => $field,
                    GetPlayer::class => $player,
                    EndTurn::class => null,
                };
            });

        // Act
        $result = $this->virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        
        $this->assertEquals('ai_thinking', $result[0]['type']);
        $this->assertEquals('ai_decision', $result[1]['type']);
        $this->assertEquals('turn_ended', $result[2]['type']);
    }

    #[Test]
    public function shouldHandleStunnedPlayer(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        $game = $this->createMockGame();
        $turn = $this->createMockTurn($turnId);
        $field = $this->createMockField();
        $player = $this->createMockPlayer(true); // stunned

        $this->messageBus->expects($this->exactly(5))
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($game, $turn, $field, $player) {
                return match (get_class($command)) {
                    GetGame::class => $game,
                    GetCurrentTurn::class => $turn,
                    GetField::class => $field,
                    GetPlayer::class => $player,
                    EndTurn::class => null,
                };
            });

        // Act
        $result = $this->virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        
        $this->assertEquals('ai_thinking', $result[0]['type']);
        $this->assertEquals('player_stunned', $result[1]['type']);
        $this->assertEquals('turn_ended', $result[2]['type']);
        $this->assertEquals('stunned', $result[2]['details']['reason']);
    }

    #[Test]
    public function shouldHandleExceptions(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();

        $this->messageBus->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willThrowException(new \Exception('Test error'));

        // Act
        $result = $this->virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        $errorAction = array_filter($result, fn($action) => $action['type'] === 'ai_error');
        $this->assertNotEmpty($errorAction);
    }

    private function createMockGame(): object
    {
        return new class {
            public function getId(): Uuid { return Uuid::v7(); }
        };
    }

    private function createMockTurn(Uuid $turnId): object
    {
        return new class($turnId) {
            public function __construct(private Uuid $turnId) {}
            public function getTurnId(): Uuid { return $this->turnId; }
            public function getGameId(): Uuid { return Uuid::v7(); }
            public function getPlayerId(): Uuid { return Uuid::v7(); }
        };
    }

    private function createMockField(): object
    {
        return new class {
            public function getId(): Uuid { return Uuid::v7(); }
        };
    }

    private function createMockPlayer(bool $isDefeated): object
    {
        return new class($isDefeated) {
            public function __construct(private bool $isDefeated) {}
            public function isDefeated(): bool { return $this->isDefeated; }
            public function getPlayerId(): Uuid { return Uuid::v7(); }
        };
    }
}