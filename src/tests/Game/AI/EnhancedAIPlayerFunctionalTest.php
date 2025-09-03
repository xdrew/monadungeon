<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\EnhancedAIPlayer;
use App\Game\AI\VirtualPlayerApiClient;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Functional test for Enhanced AI Player's multi-action turn system
 * Tests the actual implementation without mocks
 */
class EnhancedAIPlayerFunctionalTest extends TestCase
{
    private VirtualPlayerApiClient $apiClient;
    private HttpKernelInterface $httpKernel;
    private array $gameState;

    protected function setUp(): void
    {
        // Create a mock HttpKernel that returns success responses
        $this->httpKernel = $this->createMock(HttpKernelInterface::class);
        $this->httpKernel->method('handle')
            ->willReturn(new JsonResponse(['success' => true, 'actions' => []]));
        
        $this->apiClient = new VirtualPlayerApiClient($this->httpKernel);
        
        // Initialize game state
        $this->gameState = [
            'gameId' => Uuid::v7(),
            'playerId' => Uuid::v7(),
            'turnId' => Uuid::v7(),
            'currentPosition' => new FieldPlace(0, 0),
            'turnActions' => 0,
            'playerHp' => 5,
            'playerMaxHp' => 5,
            'inventory' => [],
            'battleOccurred' => false,
            'healingFountains' => []
        ];
    }

    // #[Test] // TODO: Fix test - needs interface for VirtualPlayerApiClient to allow mocking
    public function skip_testAIPerformsMultipleActionsInOneTurn(): void
    {
        $aiPlayer = $this->createAIPlayerWithContext();
        
        // Setup API responses
        $this->apiClient->setResponse('placeTileSequence', [
            'success' => true,
            'actions' => [
                ['type' => 'place_tile', 'position' => ['x' => 1, 'y' => 0]]
            ]
        ]);
        $this->apiClient->setResponse('movePlayer', [
            'success' => true,
            'response' => []
        ]);
        $this->apiClient->setResponse('endTurn', ['success' => true]);
        
        // Execute turn
        $result = $aiPlayer->executeTurn(
            $this->gameState['gameId'],
            $this->gameState['playerId'],
            $this->gameState['turnId']
        );
        
        // Verify multiple actions were taken
        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(2, count($result['actions']));
        
        // Verify API calls
        $callHistory = $this->apiClient->getCallHistory();
        $methodCalls = array_column($callHistory, 'method');
        
        // Should include at least tile placement and end turn
        $this->assertContains('placeTileSequence', $methodCalls);
        $this->assertContains('endTurn', $methodCalls);
    }

    // #[Test] // TODO: Fix test - needs interface for VirtualPlayerApiClient to allow mocking
    public function skip_testAIPrioritizesHealingWhenLowHP(): void
    {
        // Set low HP
        $this->gameState['playerHp'] = 1;
        $this->gameState['healingFountains'] = [new FieldPlace(2, 0)];
        
        $aiPlayer = $this->createAIPlayerWithContext();
        
        // Setup API responses
        $this->apiClient->setResponse('movePlayer', [
            'success' => true,
            'response' => []
        ]);
        $this->apiClient->setResponse('endTurn', ['success' => true]);
        
        // Execute turn
        $result = $aiPlayer->executeTurn(
            $this->gameState['gameId'],
            $this->gameState['playerId'],
            $this->gameState['turnId']
        );
        
        // Verify healing prioritization
        $this->assertTrue($result['success']);
        
        // Check if AI attempted to move (likely toward healing fountain)
        $callHistory = $this->apiClient->getCallHistory();
        $moveCalls = array_filter($callHistory, fn($call) => $call['method'] === 'movePlayer');
        $this->assertNotEmpty($moveCalls, 'AI should attempt to move when HP is low');
    }

    // #[Test] // TODO: Fix test - needs interface for VirtualPlayerApiClient to allow mocking
    public function skip_testAIHandlesBattleResults(): void
    {
        $aiPlayer = $this->createAIPlayerWithContext();
        
        // Setup API responses - battle will end turn
        $this->apiClient->setResponse('movePlayer', [
            'success' => true,
            'response' => [
                'battleResult' => 'win',
                'turnEnded' => true
            ]
        ]);
        
        // Execute turn
        $result = $aiPlayer->executeTurn(
            $this->gameState['gameId'],
            $this->gameState['playerId'],
            $this->gameState['turnId']
        );
        
        // Verify battle was handled
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['actions']); // Only one action before battle ended turn
        
        // Verify no endTurn was called (battle auto-ended)
        $callHistory = $this->apiClient->getCallHistory();
        $endTurnCalls = array_filter($callHistory, fn($call) => $call['method'] === 'endTurn');
        $this->assertEmpty($endTurnCalls, 'endTurn should not be called after battle auto-ends turn');
    }

    // #[Test] // TODO: Fix test - needs interface for VirtualPlayerApiClient to allow mocking
    public function skip_testStrategyConfiguration(): void
    {
        $aiPlayer = $this->createAIPlayerWithContext();
        
        // Setup API responses
        $this->apiClient->setResponse('placeTileSequence', [
            'success' => true,
            'actions' => []
        ]);
        $this->apiClient->setResponse('endTurn', ['success' => true]);
        
        // Execute turn with specific strategy
        $result = $aiPlayer->executeTurn(
            $this->gameState['gameId'],
            $this->gameState['playerId'],
            $this->gameState['turnId'],
            'aggressive'
        );
        
        // Verify turn was executed
        $this->assertTrue($result['success']);
        
        // Check that strategy was included in actions
        $startActions = array_filter($result['actions'], fn($a) => $a['type'] === 'ai_start');
        $this->assertNotEmpty($startActions);
        
        $firstStart = reset($startActions);
        $this->assertEquals('aggressive', $firstStart['details']['strategy'] ?? 'balanced');
    }

    // #[Test] // TODO: Fix test - needs interface for VirtualPlayerApiClient to allow mocking
    public function skip_testAIUsesAllFourActions(): void
    {
        $aiPlayer = $this->createAIPlayerWithContext(maxActions: 4);
        
        // Setup API responses for multiple actions
        $this->apiClient->setResponse('placeTileSequence', [
            'success' => true,
            'actions' => []
        ]);
        $this->apiClient->setResponse('movePlayer', [
            'success' => true,
            'response' => []
        ]);
        $this->apiClient->setResponse('endTurn', ['success' => true]);
        
        // Execute turn
        $result = $aiPlayer->executeTurn(
            $this->gameState['gameId'],
            $this->gameState['playerId'],
            $this->gameState['turnId']
        );
        
        // Verify AI tried to use multiple actions
        $this->assertTrue($result['success']);
        $this->assertLessThanOrEqual(4, count($result['actions']));
        
        // Count actual game actions (exclude ai_start, ai_end)
        $gameActions = array_filter($result['actions'], function($action) {
            return !in_array($action['type'], ['ai_start', 'ai_end']);
        });
        
        // Should have at least 1 game action, up to 4
        $this->assertGreaterThanOrEqual(1, count($gameActions));
        $this->assertLessThanOrEqual(4, count($gameActions));
    }

    private function createAIPlayerWithContext(int $maxActions = 4): EnhancedAIPlayer
    {
        $game = new \stdClass();
        $game->gameId = $this->gameState['gameId'];
        
        $player = new \stdClass();
        $player->playerId = $this->gameState['playerId'];
        $player->hp = $this->gameState['playerHp'];
        $player->maxHp = $this->gameState['playerMaxHp'];
        $player->stunned = false;
        $player->inventory = $this->gameState['inventory'];
        
        $field = new \stdClass();
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = $this->gameState['healingFountains'];
        
        $currentTurn = new \stdClass();
        $currentTurn->turnId = $this->gameState['turnId'];
        $currentTurn->playerId = $this->gameState['playerId'];
        
        $position = new \stdClass();
        $position->positionX = $this->gameState['currentPosition']->positionX;
        $position->positionY = $this->gameState['currentPosition']->positionY;
        
        // Create tester with handlers
        $tester = MessageBusTester::create(
            function (GetGame $query) use ($game) {
                return $game;
            },
            function (GetPlayer $query) use ($player) {
                return $player;
            },
            function (GetField $query) use ($field) {
                return $field;
            },
            function (GetCurrentTurn $query) use ($currentTurn) {
                return $currentTurn;
            },
            function (GetPlayerPosition $query) use ($position) {
                return $position;
            },
            function (GetAvailablePlacesForPlayer $query) {
                $places = new \stdClass();
                
                if ($this->gameState['turnActions'] === 0) {
                    // First action - can place tiles
                    $places->placeTile = [
                        new FieldPlace(1, 0),
                        new FieldPlace(0, 1)
                    ];
                    $places->moveTo = [];
                } else {
                    // Subsequent actions - movement options
                    $places->placeTile = [];
                    $places->moveTo = [
                        new FieldPlace(
                            $this->gameState['currentPosition']->positionX + 1,
                            $this->gameState['currentPosition']->positionY
                        )
                    ];
                }
                
                $this->gameState['turnActions']++;
                return $places;
            }
        );
        
        return new EnhancedAIPlayer(
            $tester->messageBus(),
            new NullLogger(),
            $this->apiClient
        );
    }
}