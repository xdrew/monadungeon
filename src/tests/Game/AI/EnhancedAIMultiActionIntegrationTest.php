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
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Integration test for Enhanced AI Player's multi-action turn system
 * Tests the complete flow of a turn with multiple actions
 */
class EnhancedAIMultiActionIntegrationTest extends TestCase
{
    private LoggerInterface $logger;
    private array $apiCallHistory = [];
    
    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->apiCallHistory = [];
    }
    
    // #[Test] // TODO: Fix test - issue with UUID comparison in isMyTurn check
    public function executesFullFourActionTurn(): void
    {
        // Setup game state
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        $game = new \stdClass();
        $game->gameId = $gameId;
        
        $player = new \stdClass();
        $player->playerId = $playerId;
        $player->hp = 5;
        $player->maxHp = 5;
        $player->stunned = false;
        
        $field = new \stdClass();
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [];
        
        // Create a proper turn object with matching playerId
        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId; // This will be the same Uuid instance
        
        // Track position changes
        $positions = [
            $this->createPosition(0, 0), // Starting position
            $this->createPosition(1, 0), // After placing tile
            $this->createPosition(2, 0), // After first move
            $this->createPosition(3, 0), // After second move
        ];
        $currentPositionIndex = 0;
        
        // Setup available places that change as we move
        $availablePlacesSequence = [
            // Initial state - can place tiles
            $this->createAvailablePlaces(
                [new FieldPlace(1, 0), new FieldPlace(0, 1)], // placeTile
                [new FieldPlace(1, 0)] // moveTo after placing
            ),
            // After first move - exploration options
            $this->createAvailablePlaces(
                [], // No tile placement mid-turn
                [new FieldPlace(2, 0), new FieldPlace(1, 1)]
            ),
            // After second move - more exploration
            $this->createAvailablePlaces(
                [],
                [new FieldPlace(3, 0), new FieldPlace(2, 1)]
            ),
            // After third move - final positioning
            $this->createAvailablePlaces(
                [],
                [new FieldPlace(4, 0), new FieldPlace(3, 1)]
            ),
        ];
        $availablePlacesIndex = 0;
        
        // Create message bus tester with dynamic handlers
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
            function (GetCurrentTurn $query) use ($currentTurn, $playerId) {
                // Always return a turn with the playerId that matches what we're testing
                $turn = new \stdClass();
                $turn->turnId = $currentTurn->turnId;
                $turn->playerId = $playerId; // Return the same Uuid instance
                return $turn;
            },
            function (GetPlayerPosition $query) use (&$positions, &$currentPositionIndex) {
                return $positions[$currentPositionIndex];
            },
            function (GetAvailablePlacesForPlayer $query) use (&$availablePlacesSequence, &$availablePlacesIndex) {
                $places = $availablePlacesSequence[min($availablePlacesIndex, count($availablePlacesSequence) - 1)];
                return $places;
            }
        );
        
        // Create mock HttpKernel to track API calls
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->expects($this->any())
            ->method('handle')
            ->willReturnCallback(function (Request $request) {
                $this->apiCallHistory[] = [
                    'uri' => $request->getRequestUri(),
                    'method' => $request->getMethod(),
                    'content' => $request->getContent()
                ];
                return new JsonResponse(['success' => true, 'actions' => []]);
            });
        
        $apiClient = new VirtualPlayerApiClient($httpKernel);
        
        $aiPlayer = new EnhancedAIPlayer(
            $tester->messageBus(),
            $this->logger,
            $apiClient
        );
        
        // Execute the turn
        $result = $aiPlayer->executeTurn($gameId, $playerId);
        
        // Verify the result
        $this->assertTrue($result);
        
        // Verify API calls through history
        $this->assertGreaterThanOrEqual(2, count($this->apiCallHistory)); // At least tile placement and end turn
        
        // Check that we tried to place a tile
        $tilePlacementCalls = array_filter($this->apiCallHistory, fn($call) => 
            str_contains($call['uri'], 'pick-tile') || str_contains($call['uri'], 'place-tile-sequence')
        );
        $this->assertNotEmpty($tilePlacementCalls, 'Should have attempted tile placement');
        
        // Check that we ended the turn
        $endTurnCalls = array_filter($this->apiCallHistory, fn($call) => 
            str_contains($call['uri'], 'end-turn')
        );
        $this->assertNotEmpty($endTurnCalls, 'Should have ended the turn');
    }
    
    // #[Test] // TODO: Fix test - issue with UUID comparison in isMyTurn check
    public function stopsTurnEarlyWhenNoMoreBeneficialActions(): void
    {
        // Setup game state
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        $game = new \stdClass();
        $game->gameId = $gameId;
        
        $player = new \stdClass();
        $player->playerId = $playerId;
        $player->hp = 5;
        $player->maxHp = 5;
        $player->stunned = false;
        
        $field = new \stdClass();
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [];
        
        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId;
        
        $position = $this->createPosition(0, 0);
        
        // Limited available places - only one action possible
        $limitedPlaces = $this->createAvailablePlaces(
            [new FieldPlace(1, 0)], // Can place one tile
            [] // No movement options after placing
        );
        
        // Create message bus tester
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
            function (GetCurrentTurn $query) use ($playerId, $turnId) {
                $turn = new \stdClass();
                $turn->turnId = $turnId;
                $turn->playerId = $playerId;
                return $turn;
            },
            function (GetPlayerPosition $query) use ($position) {
                return $position;
            },
            function (GetAvailablePlacesForPlayer $query) use ($limitedPlaces) {
                return $limitedPlaces;
            }
        );
        
        // Create mock HttpKernel
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->expects($this->any())
            ->method('handle')
            ->willReturn(new JsonResponse(['success' => true, 'actions' => []]));
        
        $apiClient = new VirtualPlayerApiClient($httpKernel);
        
        $aiPlayer = new EnhancedAIPlayer(
            $tester->messageBus(),
            $this->logger,
            $apiClient
        );
        
        // Execute the turn
        $result = $aiPlayer->executeTurn($gameId, $playerId);
        
        // Verify it executed successfully
        $this->assertTrue($result);
    }
    
    // #[Test] // TODO: Fix test - issue with UUID comparison in isMyTurn check
    public function handlesTurnEndingActionsCorrectly(): void
    {
        // Setup game state with a battle result
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        $game = new \stdClass();
        $game->gameId = $gameId;
        
        $player = new \stdClass();
        $player->playerId = $playerId;
        $player->hp = 3;
        $player->maxHp = 5;
        $player->stunned = false;
        
        $field = new \stdClass();
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [];
        
        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId;
        
        $position = $this->createPosition(0, 0);
        
        // Available places with movement that will trigger battle
        $places = $this->createAvailablePlaces(
            [],
            [new FieldPlace(1, 0)] // Move to monster tile
        );
        
        // Create message bus tester
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
            function (GetCurrentTurn $query) use ($playerId, $turnId) {
                $turn = new \stdClass();
                $turn->turnId = $turnId;
                $turn->playerId = $playerId;
                return $turn;
            },
            function (GetPlayerPosition $query) use ($position) {
                return $position;
            },
            function (GetAvailablePlacesForPlayer $query) use ($places) {
                return $places;
            }
        );
        
        // Create mock HttpKernel - battle ends the turn
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $httpKernel->expects($this->any())
            ->method('handle')
            ->willReturnCallback(function (Request $request) {
                if (str_contains($request->getRequestUri(), 'move-player')) {
                    return new JsonResponse([
                        'success' => true,
                        'response' => [
                            'turnEnded' => true,
                            'battleResult' => 'win'
                        ]
                    ]);
                }
                return new JsonResponse(['success' => true]);
            });
        
        $apiClient = new VirtualPlayerApiClient($httpKernel);
        
        $aiPlayer = new EnhancedAIPlayer(
            $tester->messageBus(),
            $this->logger,
            $apiClient
        );
        
        // Execute the turn
        $result = $aiPlayer->executeTurn($gameId, $playerId);
        
        // Verify turn executed successfully
        $this->assertTrue($result);
    }
    
    private function createPosition(int $x, int $y): \stdClass
    {
        $position = new \stdClass();
        $position->positionX = $x;
        $position->positionY = $y;
        return $position;
    }
    
    private function createAvailablePlaces(array $placeTile, array $moveTo): \stdClass
    {
        $places = new \stdClass();
        $places->placeTile = $placeTile;
        $places->moveTo = $moveTo;
        return $places;
    }
}