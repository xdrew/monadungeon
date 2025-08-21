<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\EnhancedAIPlayer;
use App\Game\AI\VirtualPlayerApiClient;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\Field\GetField;
use App\Game\Field\Tile;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GetGame;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\Player;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Telephantast\MessageBus\MessageBus;

/**
 * Functional test for Enhanced AI Player's multi-action turn system
 * Tests the actual implementation without mocks
 */
class EnhancedAIPlayerFunctionalTest extends TestCase
{
    private MessageBus $messageBus;
    private EnhancedAIPlayer $aiPlayer;
    private array $apiCallLog = [];
    private array $gameState;

    protected function setUp(): void
    {
        $this->messageBus = new MessageBus();
        $this->apiCallLog = [];
        
        // Initialize game state
        $this->gameState = [
            'gameId' => Uuid::v7(),
            'playerId' => Uuid::v7(),
            'turnId' => Uuid::v7(),
            'hp' => 5,
            'maxHp' => 5,
            'currentPosition' => new FieldPlace(0, 0),
            'turnActions' => 0,
        ];
        
        // Create HTTP kernel that simulates API responses
        $apiCallLog = &$this->apiCallLog;
        $gameState = &$this->gameState;
        
        $httpKernel = new class($gameState, $apiCallLog) implements HttpKernelInterface {
            private array $gameState;
            private array $apiCallLog;
            
            public function __construct(array &$gameState, array &$apiCallLog)
            {
                $this->gameState = &$gameState;
                $this->apiCallLog = &$apiCallLog;
            }
            
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $path = $request->getPathInfo();
                $data = json_decode($request->getContent(), true);
                
                // Log the API call
                $this->apiCallLog[] = [
                    'path' => $path,
                    'method' => $request->getMethod(),
                    'data' => $data,
                    'action_number' => $this->gameState['turnActions'] + 1
                ];
                
                // Simulate API responses based on path
                switch ($path) {
                    case '/api/game/place-tile-sequence':
                        $this->gameState['turnActions']++;
                        $this->gameState['currentPosition'] = new FieldPlace($data['x'], $data['y']);
                        return new JsonResponse([
                            'success' => true,
                            'actions' => [
                                ['step' => 'pick_tile', 'result' => ['success' => true]],
                                ['step' => 'place_tile', 'result' => ['success' => true]],
                                ['step' => 'move_player', 'result' => ['success' => true]]
                            ]
                        ]);
                        
                    case '/api/game/move-player':
                        $this->gameState['turnActions']++;
                        $this->gameState['currentPosition'] = new FieldPlace($data['toX'], $data['toY']);
                        return new JsonResponse([
                            'success' => true,
                            'currentPosition' => ['x' => $data['toX'], 'y' => $data['toY']]
                        ]);
                        
                    case '/api/game/end-turn':
                        return new JsonResponse(['success' => true]);
                        
                    case '/api/game/pick-item':
                        return new JsonResponse(['success' => true]);
                        
                    case '/api/game/finalize-battle':
                        return new JsonResponse(['success' => true]);
                        
                    default:
                        return new JsonResponse(['error' => 'Unknown endpoint'], 404);
                }
            }
        };
        
        $apiClient = new VirtualPlayerApiClient($httpKernel);
        $logger = new NullLogger();
        
        $this->aiPlayer = new EnhancedAIPlayer(
            $this->messageBus,
            $logger,
            $apiClient
        );
        
        // Setup message bus handlers
        $this->setupMessageBusHandlers();
    }
    
    private function setupMessageBusHandlers(): void
    {
        $game = new class($this->gameState) {
            public Uuid $gameId;
            
            public function __construct(array &$gameState)
            {
                $this->gameId = $gameState['gameId'];
            }
        };
        
        $player = new class($this->gameState) {
            public Uuid $playerId;
            public int $hp;
            public int $maxHp;
            
            public function __construct(array &$gameState)
            {
                $this->playerId = $gameState['playerId'];
                $this->hp = &$gameState['hp'];
                $this->maxHp = &$gameState['maxHp'];
            }
        };
        
        $field = new class() {
            public array $tiles = [];
            public array $items = [];
            public array $healingFountainPositions = [];
            public array $size = ['minX' => -5, 'maxX' => 5, 'minY' => -5, 'maxY' => 5];
        };
        
        $currentTurn = new class($this->gameState) {
            public Uuid $turnId;
            public Uuid $playerId;
            
            public function __construct(array &$gameState)
            {
                $this->turnId = $gameState['turnId'];
                $this->playerId = $gameState['playerId'];
            }
        };
        
        // Register handlers
        $this->messageBus->addHandler(GetGame::class, fn() => $game);
        $this->messageBus->addHandler(GetPlayer::class, fn() => $player);
        $this->messageBus->addHandler(GetField::class, fn() => $field);
        $this->messageBus->addHandler(GetCurrentTurn::class, fn() => $currentTurn);
        $this->messageBus->addHandler(GetPlayerPosition::class, fn() => $this->gameState['currentPosition']);
        
        // Dynamic available places based on turn actions
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () {
            $places = new \stdClass();
            
            if ($this->gameState['turnActions'] === 0) {
                // First action - can place tiles
                $places->placeTile = [
                    new FieldPlace(1, 0),
                    new FieldPlace(0, 1)
                ];
                $places->moveTo = [];
            } else {
                // Subsequent actions - can move
                $places->placeTile = [];
                $places->moveTo = [
                    new FieldPlace($this->gameState['currentPosition']->positionX + 1, $this->gameState['currentPosition']->positionY),
                    new FieldPlace($this->gameState['currentPosition']->positionX, $this->gameState['currentPosition']->positionY + 1),
                ];
            }
            
            return $places;
        });
    }

    #[Test]
    public function testAIPerformsMultipleActionsInOneTurn(): void
    {
        // Act
        $result = $this->aiPlayer->executeTurn(
            $this->gameState['gameId'],
            $this->gameState['playerId']
        );
        
        // Assert
        $this->assertTrue($result, 'AI turn should complete successfully');
        
        // Check that multiple actions were performed
        $actionCount = count(array_filter($this->apiCallLog, function ($log) {
            return in_array($log['path'], ['/api/game/place-tile-sequence', '/api/game/move-player']);
        }));
        
        $this->assertGreaterThanOrEqual(2, $actionCount, 'AI should perform at least 2 actions');
        $this->assertLessThanOrEqual(4, $actionCount, 'AI should not perform more than 4 actions');
        
        // Verify turn was ended
        $endTurnCalls = array_filter($this->apiCallLog, fn($log) => $log['path'] === '/api/game/end-turn');
        $this->assertCount(1, $endTurnCalls, 'Turn should be ended exactly once');
        
        // Verify action sequence
        if (!empty($this->apiCallLog)) {
            $firstAction = $this->apiCallLog[0];
            $this->assertEquals('/api/game/place-tile-sequence', $firstAction['path'], 'First action should be tile placement');
        }
    }

    #[Test]
    public function testAIPrioritizesHealingWhenLowHP(): void
    {
        // Setup low HP scenario
        $this->gameState['hp'] = 2;
        
        // Add healing fountain to field
        $field = new class() {
            public array $tiles = [];
            public array $items = [];
            public array $healingFountainPositions = ['2,2'];
            public array $size = ['minX' => -5, 'maxX' => 5, 'minY' => -5, 'maxY' => 5];
        };
        
        $this->messageBus->addHandler(GetField::class, fn() => $field);
        
        // Update available places to include healing fountain
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () {
            $places = new \stdClass();
            $places->placeTile = [];
            $places->moveTo = [new FieldPlace(2, 2)]; // Healing fountain position
            return $places;
        });
        
        // Act
        $result = $this->aiPlayer->executeTurn(
            $this->gameState['gameId'],
            $this->gameState['playerId']
        );
        
        // Assert
        $this->assertTrue($result);
        
        // Check if AI moved to healing fountain
        $moveCalls = array_filter($this->apiCallLog, fn($log) => $log['path'] === '/api/game/move-player');
        $this->assertNotEmpty($moveCalls, 'AI should attempt to move when low HP');
        
        if (!empty($moveCalls)) {
            $firstMove = reset($moveCalls);
            $this->assertEquals(2, $firstMove['data']['toX']);
            $this->assertEquals(2, $firstMove['data']['toY']);
        }
    }

    #[Test]
    public function testAIHandlesBattleResults(): void
    {
        // Create HTTP kernel that returns battle info
        $gameState = &$this->gameState;
        $apiCallLog = &$this->apiCallLog;
        
        $httpKernel = new class($gameState, $apiCallLog) implements HttpKernelInterface {
            private array $gameState;
            private array $apiCallLog;
            
            public function __construct(array &$gameState, array &$apiCallLog)
            {
                $this->gameState = &$gameState;
                $this->apiCallLog = &$apiCallLog;
            }
            
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $path = $request->getPathInfo();
                $data = json_decode($request->getContent(), true);
                
                $this->apiCallLog[] = ['path' => $path, 'data' => $data];
                
                if ($path === '/api/game/move-player') {
                    // Simulate battle encounter
                    return new JsonResponse([
                        'success' => true,
                        'battleInfo' => [
                            'battleId' => Uuid::v7()->toString(),
                            'result' => 'win',
                            'monsterType' => 'skeleton',
                            'position' => '1,1',
                            'reward' => ['itemId' => Uuid::v7()->toString(), 'type' => 'sword']
                        ]
                    ]);
                }
                
                if ($path === '/api/game/pick-item') {
                    return new JsonResponse(['success' => true]);
                }
                
                return new JsonResponse(['success' => true]);
            }
        };
        
        $apiClient = new VirtualPlayerApiClient($httpKernel);
        $aiPlayer = new EnhancedAIPlayer(
            $this->messageBus,
            new NullLogger(),
            $apiClient
        );
        
        // Act
        $result = $aiPlayer->executeTurn(
            $this->gameState['gameId'],
            $this->gameState['playerId']
        );
        
        // Assert
        $this->assertTrue($result);
        
        // Check if item pickup was attempted after battle
        $pickItemCalls = array_filter($this->apiCallLog, fn($log) => $log['path'] === '/api/game/pick-item');
        $this->assertNotEmpty($pickItemCalls, 'AI should attempt to pick up item after winning battle');
    }

    #[Test]
    public function testStrategyConfiguration(): void
    {
        // Test default configuration
        $config = $this->aiPlayer->getStrategyConfig();
        $this->assertTrue($config['aggressive']);
        $this->assertEquals(2, $config['healingThreshold']);
        
        // Test updating configuration
        $this->aiPlayer->setStrategyConfig([
            'aggressive' => false,
            'healingThreshold' => 3,
            'preferTreasures' => false
        ]);
        
        $updatedConfig = $this->aiPlayer->getStrategyConfig();
        $this->assertFalse($updatedConfig['aggressive']);
        $this->assertEquals(3, $updatedConfig['healingThreshold']);
        $this->assertFalse($updatedConfig['preferTreasures']);
    }

    #[Test] 
    public function testAIUsesAllFourActions(): void
    {
        // Setup to ensure all 4 actions are available
        $actionCounter = 0;
        $gameState = &$this->gameState;
        $apiCallLog = &$this->apiCallLog;
        
        $httpKernel = new class($gameState, $apiCallLog, $actionCounter) implements HttpKernelInterface {
            private array $gameState;
            private array $apiCallLog;
            private int $actionCounter;
            
            public function __construct(array &$gameState, array &$apiCallLog, int &$actionCounter)
            {
                $this->gameState = &$gameState;
                $this->apiCallLog = &$apiCallLog;
                $this->actionCounter = &$actionCounter;
            }
            
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $path = $request->getPathInfo();
                
                if (in_array($path, ['/api/game/place-tile-sequence', '/api/game/move-player'])) {
                    $this->actionCounter++;
                    $this->apiCallLog[] = [
                        'path' => $path,
                        'action_number' => $this->actionCounter
                    ];
                }
                
                return new JsonResponse(['success' => true]);
            }
        };
        
        $apiClient = new VirtualPlayerApiClient($httpKernel);
        $aiPlayer = new EnhancedAIPlayer(
            $this->messageBus,
            new NullLogger(),
            $apiClient
        );
        
        // Ensure multiple moves are available
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () use (&$actionCounter) {
            $places = new \stdClass();
            
            if ($actionCounter === 0) {
                $places->placeTile = [new FieldPlace(1, 0)];
                $places->moveTo = [];
            } else {
                $places->placeTile = [];
                $places->moveTo = [
                    new FieldPlace($actionCounter, 0),
                    new FieldPlace(0, $actionCounter)
                ];
            }
            
            return $places;
        });
        
        // Act
        $result = $aiPlayer->executeTurn(
            $this->gameState['gameId'],
            $this->gameState['playerId']
        );
        
        // Assert
        $this->assertTrue($result);
        $this->assertEquals(4, $actionCounter, 'AI should use all 4 available actions');
        
        // Verify actions are numbered correctly
        foreach ($this->apiCallLog as $index => $log) {
            if (isset($log['action_number'])) {
                $this->assertEquals($index + 1, $log['action_number'], 'Actions should be numbered sequentially');
            }
        }
    }
}