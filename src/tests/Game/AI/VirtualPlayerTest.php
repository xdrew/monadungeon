<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\VirtualPlayer;
use App\Game\AI\VirtualPlayerStrategy;
use App\Game\Deck\GetDeck;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GetGame;
use App\Game\Player\GetPlayer;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\TestCase;
use Telephantast\MessageBus\MessageBus;

class VirtualPlayerTest extends TestCase
{
    private MessageBus $messageBus;
    private VirtualPlayerStrategy $strategy;
    private VirtualPlayer $virtualPlayer;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBus::class);
        $this->strategy = $this->createMock(VirtualPlayerStrategy::class);
        $this->virtualPlayer = new VirtualPlayer($this->messageBus, $this->strategy);
    }

    #[Test]
    public function shouldExecuteTurnWithoutErrors(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        
        // Mock game entities
        $game = $this->createMockGame();
        $turn = $this->createMockTurn();
        $field = $this->createMockField();
        $player = $this->createMockPlayer();

        // Setup message bus expectations
        $this->messageBus->expects($this->exactly(4))
            ->method('dispatch')
            ->willReturnMap([
                [new GetGame($gameId), $game],
                [new GetCurrentTurn($gameId), $turn],
                [new GetField($gameId), $field],
                [new GetPlayer($playerId, $gameId), $player],
            ]);

        // Act
        $result = $this->virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Should have at least thinking action
        $thinkingAction = $result[0];
        $this->assertEquals('ai_thinking', $thinkingAction['type']);
        $this->assertEquals('Analyzing game state...', $thinkingAction['details']['message']);
    }

    #[Test]
    public function shouldHandleStunnedPlayerCorrectly(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        
        $game = $this->createMockGame();
        $turn = $this->createMockTurn();
        $field = $this->createMockField();
        $player = $this->createMockPlayer(isDefeated: true); // Stunned player

        $this->messageBus->expects($this->exactly(5))
            ->method('dispatch')
            ->willReturnMap([
                [new GetGame($gameId), $game],
                [new GetCurrentTurn($gameId), $turn],
                [new GetField($gameId), $field],
                [new GetPlayer($playerId, $gameId), $player],
                [new EndTurn($turn->getTurnId(), $gameId, $playerId), null],
            ]);

        // Act
        $result = $this->virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        
        // Should have stunned action
        $stunnedAction = array_filter($result, fn($action) => $action['type'] === 'player_stunned');
        $this->assertNotEmpty($stunnedAction);
        
        // Should have turn ended action
        $turnEndedAction = array_filter($result, fn($action) => $action['type'] === 'turn_ended');
        $this->assertNotEmpty($turnEndedAction);
    }

    #[Test]
    public function shouldHandleErrorsGracefully(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        
        // Make message bus throw an exception
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new \Exception('Test error'));

        // Act
        $result = $this->virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        
        // Should have error action
        $errorAction = array_filter($result, fn($action) => $action['type'] === 'ai_error');
        $this->assertNotEmpty($errorAction);
    }

    private function createMockGame(): object
    {
        return new class {
            public function getId(): Uuid { return Uuid::v7(); }
        };
    }

    private function createMockTurn(): object
    {
        return new class {
            public function getTurnId(): Uuid { return Uuid::v7(); }
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

    private function createMockPlayer(bool $isDefeated = false): object
    {
        return new class($isDefeated) {
            public function __construct(private bool $isDefeated) {}
            public function isDefeated(): bool { return $this->isDefeated; }
            public function getPlayerId(): Uuid { return Uuid::v7(); }
        };
    }
}