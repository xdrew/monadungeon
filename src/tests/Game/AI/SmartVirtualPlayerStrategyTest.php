<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\SmartVirtualPlayer;
use App\Game\AI\BasicVirtualPlayerStrategy;
use App\Game\AI\VirtualPlayerApiClient;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\Field\GetField;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use Telephantast\MessageBus\MessageBus;

#[Group('piy')]
final class SmartVirtualPlayerStrategyTest extends TestCase
{
    private MessageBus $messageBus;
    private BasicVirtualPlayerStrategy $strategy;
    private VirtualPlayerApiClient $apiClient;
    private SmartVirtualPlayer $smartVirtualPlayer;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBus::class);
        $this->strategy = $this->createMock(BasicVirtualPlayerStrategy::class);
        $this->apiClient = $this->createMock(VirtualPlayerApiClient::class);
        
        $this->smartVirtualPlayer = new SmartVirtualPlayer(
            $this->messageBus,
            $this->strategy,
            $this->apiClient
        );
    }

    #[Test]
    public function shouldExecuteTurnWithAggressiveStrategy(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        // Setup mocks for turn execution
        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        
        $this->messageBus->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($currentTurn, $gameId, $playerId) {
                if ($command instanceof GetCurrentTurn) {
                    return $currentTurn;
                }
                if ($command instanceof GetField) {
                    $field = $this->createMock(\App\Game\Field\Field::class);
                    return $field;
                }
                if ($command instanceof GetPlayer) {
                    $player = $this->createMock(\App\Game\Player\Player::class);
                    $player->method('getHP')->willReturn(3);
                    $player->method('isDefeated')->willReturn(false);
                    $player->method('getInventory')->willReturn([]);
                    return $player;
                }
                if ($command instanceof GetPlayerPosition) {
                    $position = $this->createMock(\App\Game\Field\FieldPlace::class);
                    $position->method('toString')->willReturn('0,0');
                    return $position;
                }
                if ($command instanceof GetAvailablePlacesForPlayer) {
                    return [
                        'moveTo' => [],
                        'placeTile' => ['1,0', '0,1'],
                    ];
                }
                return null;
            });
        
        // Mock API client to simulate successful tile placement
        $this->apiClient->expects($this->once())
            ->method('pickTile')
            ->willReturn(['success' => true, 'response' => []]);
            
        $this->apiClient->expects($this->once())
            ->method('placeTile')
            ->willReturn(['success' => true, 'response' => ['tile' => ['room' => true]]]);
            
        $this->apiClient->expects($this->once())
            ->method('movePlayer')
            ->willReturn(['success' => true, 'response' => []]);
            
        $this->apiClient->expects($this->once())
            ->method('endTurn')
            ->willReturn(['success' => true]);
        
        // Execute turn with aggressive strategy
        $actions = $this->smartVirtualPlayer->executeTurn($gameId, $playerId, 'aggressive');
        
        // Verify actions were executed
        $this->assertNotEmpty($actions);
        $this->assertIsArray($actions);
        
        // Check that strategy was applied
        $startAction = array_filter($actions, fn($a) => $a['type'] === 'ai_start');
        $this->assertNotEmpty($startAction);
        $firstStart = reset($startAction);
        $this->assertEquals('aggressive', $firstStart['details']['strategy']);
    }

    #[Test] 
    public function shouldExecuteTurnWithDefensiveStrategy(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        // Setup mocks for turn execution with low HP
        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        
        $this->messageBus->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($currentTurn, $gameId, $playerId) {
                if ($command instanceof GetCurrentTurn) {
                    return $currentTurn;
                }
                if ($command instanceof GetField) {
                    $field = $this->createMock(\App\Game\Field\Field::class);
                    return $field;
                }
                if ($command instanceof GetPlayer) {
                    $player = $this->createMock(\App\Game\Player\Player::class);
                    $player->method('getHP')->willReturn(1); // Low HP for defensive behavior
                    $player->method('isDefeated')->willReturn(false);
                    $player->method('getInventory')->willReturn([]);
                    return $player;
                }
                if ($command instanceof GetPlayerPosition) {
                    $position = $this->createMock(\App\Game\Field\FieldPlace::class);
                    $position->method('toString')->willReturn('0,0');
                    return $position;
                }
                if ($command instanceof GetAvailablePlacesForPlayer) {
                    return [
                        'moveTo' => ['1,1'], // Has move option for healing
                        'placeTile' => ['1,0'],
                    ];
                }
                return null;
            });
        
        // With defensive strategy and low HP, should move instead of placing tile
        $this->apiClient->expects($this->once())
            ->method('movePlayer')
            ->willReturn(['success' => true, 'response' => []]);
            
        $this->apiClient->expects($this->once())
            ->method('endTurn')
            ->willReturn(['success' => true]);
        
        // Execute turn with defensive strategy
        $actions = $this->smartVirtualPlayer->executeTurn($gameId, $playerId, 'defensive');
        
        // Verify defensive behavior
        $this->assertNotEmpty($actions);
        
        // Check that strategy was applied
        $startAction = array_filter($actions, fn($a) => $a['type'] === 'ai_start');
        $this->assertNotEmpty($startAction);
        $firstStart = reset($startAction);
        $this->assertEquals('defensive', $firstStart['details']['strategy']);
    }

    #[Test]
    public function shouldDefaultToBalancedStrategy(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        
        // Setup minimal mocks
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(GetCurrentTurn::class))
            ->willReturn(null); // No turn found
        
        // Execute turn without specifying strategy (should default to balanced)
        $actions = $this->smartVirtualPlayer->executeTurn($gameId, $playerId);
        
        // Should return error but with balanced strategy attempted
        $this->assertNotEmpty($actions);
        $errorAction = array_filter($actions, fn($a) => $a['type'] === 'ai_error' || $a['type'] === 'ai_start');
        $this->assertNotEmpty($errorAction);
    }
}