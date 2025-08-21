<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\EnhancedAIPlayer;
use App\Game\AI\VirtualPlayerApiClient;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test that proves the Enhanced AI Player can perform up to 4 actions per turn
 */
class EnhancedAIMultiActionTest extends TestCase
{
    #[Test]
    public function testAIPerformsUpToFourActionsPerTurn(): void
    {
        // Track API calls to verify multi-action behavior
        $actionLog = [];
        
        // Create a simple HTTP kernel that logs all actions
        $httpKernel = new class($actionLog) implements HttpKernelInterface {
            private array $actionLog;
            
            public function __construct(array &$actionLog)
            {
                $this->actionLog = &$actionLog;
            }
            
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $path = $request->getPathInfo();
                $data = json_decode($request->getContent(), true) ?? [];
                
                // Log each action
                $this->actionLog[] = [
                    'path' => $path,
                    'method' => $request->getMethod(),
                    'timestamp' => microtime(true)
                ];
                
                // Return appropriate responses
                switch ($path) {
                    case '/api/game/place-tile-sequence':
                        return new JsonResponse([
                            'success' => true,
                            'message' => 'Tile placed and moved',
                            'actions' => [
                                ['step' => 'pick_tile'],
                                ['step' => 'place_tile'],
                                ['step' => 'move_player']
                            ]
                        ]);
                        
                    case '/api/game/move-player':
                        return new JsonResponse([
                            'success' => true,
                            'message' => 'Player moved'
                        ]);
                        
                    case '/api/game/end-turn':
                        return new JsonResponse([
                            'success' => true,
                            'message' => 'Turn ended'
                        ]);
                        
                    default:
                        return new JsonResponse(['success' => true]);
                }
            }
        };
        
        // Create the API client with our test kernel
        $apiClient = new VirtualPlayerApiClient($httpKernel);
        
        // Create a simple message bus tester with mock handlers
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        $tester = MessageBusTester::create(
            // GetGame handler
            function ($query) use ($gameId) {
                if (property_exists($query, 'gameId')) {
                    $game = new \stdClass();
                    $game->gameId = $gameId;
                    return $game;
                }
            },
            // GetPlayer handler
            function ($query) use ($playerId) {
                if (property_exists($query, 'playerId')) {
                    $player = new \stdClass();
                    $player->playerId = $playerId;
                    $player->hp = 5;
                    $player->maxHp = 5;
                    return $player;
                }
            },
            // GetField handler
            function ($query) {
                if (get_class($query) === 'App\Game\Field\GetField') {
                    $field = new \stdClass();
                    $field->tiles = [];
                    $field->items = [];
                    $field->healingFountainPositions = [];
                    return $field;
                }
            },
            // GetCurrentTurn handler
            function ($query) use ($turnId, $playerId) {
                if (get_class($query) === 'App\Game\Turn\GetCurrentTurn') {
                    $turn = new \stdClass();
                    $turn->turnId = $turnId;
                    $turn->playerId = $playerId;
                    return $turn;
                }
            },
            // GetPlayerPosition handler
            function ($query) {
                if (get_class($query) === 'App\Game\Movement\GetPlayerPosition') {
                    $position = new \stdClass();
                    $position->positionX = 0;
                    $position->positionY = 0;
                    return $position;
                }
            },
            // GetAvailablePlacesForPlayer handler - this is key for multi-action
            function ($query) {
                if (get_class($query) === 'App\Game\Field\GetAvailablePlacesForPlayer') {
                    $places = new \stdClass();
                    
                    // Provide different options based on action count
                    static $callCount = 0;
                    $callCount++;
                    
                    if ($callCount === 1) {
                        // First call - offer tile placement
                        $place1 = new \stdClass();
                        $place1->positionX = 1;
                        $place1->positionY = 0;
                        
                        $places->placeTile = [$place1];
                        $places->moveTo = [];
                    } else {
                        // Subsequent calls - offer movement options
                        $move1 = new \stdClass();
                        $move1->positionX = $callCount;
                        $move1->positionY = 0;
                        
                        $move2 = new \stdClass();
                        $move2->positionX = 0;
                        $move2->positionY = $callCount;
                        
                        $places->placeTile = [];
                        $places->moveTo = [$move1, $move2];
                    }
                    
                    return $places;
                }
            }
        );
        
        // Create the Enhanced AI Player
        $aiPlayer = new EnhancedAIPlayer(
            $tester->messageBus(),
            new NullLogger(),
            $apiClient
        );
        
        // Execute the turn
        $result = $aiPlayer->executeTurn($gameId, $playerId);
        
        // Assert the turn was successful
        $this->assertTrue($result, 'AI turn should complete successfully');
        
        // Verify multiple actions were performed
        $gameActions = array_filter($actionLog, function ($log) {
            return in_array($log['path'], [
                '/api/game/place-tile-sequence',
                '/api/game/move-player'
            ]);
        });
        
        // The AI should perform multiple actions (at least 2, up to 4)
        $this->assertGreaterThanOrEqual(2, count($gameActions), 
            'AI should perform at least 2 actions in a turn');
        
        $this->assertLessThanOrEqual(4, count($gameActions), 
            'AI should not perform more than 4 actions in a turn');
        
        // Verify turn was ended
        $endTurnActions = array_filter($actionLog, function ($log) {
            return $log['path'] === '/api/game/end-turn';
        });
        
        $this->assertCount(1, $endTurnActions, 'Turn should be ended exactly once');
        
        // Verify the sequence
        $lastAction = end($actionLog);
        $this->assertEquals('/api/game/end-turn', $lastAction['path'], 
            'Last action should be ending the turn');
        
        // Output for debugging
        echo "\nAI performed " . count($gameActions) . " game actions in this turn:\n";
        foreach ($actionLog as $index => $log) {
            echo ($index + 1) . ". " . $log['path'] . "\n";
        }
    }
    
    #[Test]
    public function testAIStopsAtFourActions(): void
    {
        $actionCount = 0;
        
        // Create kernel that counts actions
        $httpKernel = new class($actionCount) implements HttpKernelInterface {
            private int $actionCount;
            
            public function __construct(int &$actionCount)
            {
                $this->actionCount = &$actionCount;
            }
            
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $path = $request->getPathInfo();
                
                if (in_array($path, ['/api/game/place-tile-sequence', '/api/game/move-player'])) {
                    $this->actionCount++;
                }
                
                return new JsonResponse(['success' => true]);
            }
        };
        
        $apiClient = new VirtualPlayerApiClient($httpKernel);
        
        // Create tester with handlers that always provide movement options
        $tester = MessageBusTester::create(
            function ($query) {
                // Provide minimal responses for all queries
                $className = get_class($query);
                
                if (str_contains($className, 'GetGame')) {
                    $game = new \stdClass();
                    $game->gameId = Uuid::v7();
                    return $game;
                }
                
                if (str_contains($className, 'GetPlayer')) {
                    $player = new \stdClass();
                    $player->playerId = Uuid::v7();
                    $player->hp = 5;
                    $player->maxHp = 5;
                    return $player;
                }
                
                if (str_contains($className, 'GetField')) {
                    $field = new \stdClass();
                    $field->tiles = [];
                    $field->items = [];
                    $field->healingFountainPositions = [];
                    return $field;
                }
                
                if (str_contains($className, 'GetCurrentTurn')) {
                    $turn = new \stdClass();
                    $turn->turnId = Uuid::v7();
                    $turn->playerId = Uuid::v7();
                    return $turn;
                }
                
                if (str_contains($className, 'GetPlayerPosition')) {
                    $position = new \stdClass();
                    $position->positionX = 0;
                    $position->positionY = 0;
                    return $position;
                }
                
                if (str_contains($className, 'GetAvailablePlacesForPlayer')) {
                    $places = new \stdClass();
                    
                    // Always provide movement options to test the 4-action limit
                    static $callCount = 0;
                    $callCount++;
                    
                    if ($callCount === 1) {
                        // First call - tile placement
                        $place = new \stdClass();
                        $place->positionX = 1;
                        $place->positionY = 0;
                        $places->placeTile = [$place];
                        $places->moveTo = [];
                    } else {
                        // Always offer moves
                        $move = new \stdClass();
                        $move->positionX = $callCount;
                        $move->positionY = $callCount;
                        $places->placeTile = [];
                        $places->moveTo = [$move];
                    }
                    
                    return $places;
                }
                
                return null;
            }
        );
        
        $aiPlayer = new EnhancedAIPlayer(
            $tester->messageBus(),
            new NullLogger(),
            $apiClient
        );
        
        // Execute turn
        $result = $aiPlayer->executeTurn(Uuid::v7(), Uuid::v7());
        
        // Assert
        $this->assertTrue($result);
        $this->assertEquals(4, $actionCount, 'AI should perform exactly 4 actions when all are available');
    }
}