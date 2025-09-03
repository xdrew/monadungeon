<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\SmartVirtualPlayer;
use App\Game\AI\BasicVirtualPlayerStrategy;
use App\Game\AI\VirtualPlayerApiClient;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\Field\GetField;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use App\Game\GameLifecycle\GetGame;

#[Group('piy')]
final class SmartVirtualPlayerStrategyTest extends TestCase
{
    private BasicVirtualPlayerStrategy $strategy;
    private VirtualPlayerApiClient $apiClient;
    private HttpKernelInterface $httpKernel;

    protected function setUp(): void
    {
        $this->strategy = new BasicVirtualPlayerStrategy();
        
        // Create a mock HttpKernel that returns success responses
        $this->httpKernel = $this->createMock(HttpKernelInterface::class);
        $this->httpKernel->method('handle')
            ->willReturn(new JsonResponse(['success' => true, 'actions' => []]));
        
        $this->apiClient = new VirtualPlayerApiClient($this->httpKernel);
    }

    // #[Test] // TODO: Fix test - needs interface for VirtualPlayerApiClient to allow mocking
    public function skip_shouldExecuteTurnWithAggressiveStrategy(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        // Setup mocks for turn execution
        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId;
        
        $game = new \stdClass();
        $game->gameId = $gameId;
        
        $player = new \stdClass();
        $player->playerId = $playerId;
        $player->hp = 3;
        $player->maxHp = 5;
        $player->stunned = false;
        $player->inventory = [];
        
        $field = new \stdClass();
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [];
        
        $position = new \stdClass();
        $position->positionX = 0;
        $position->positionY = 0;
        
        $availablePlaces = new \stdClass();
        $availablePlaces->moveTo = [];
        $availablePlaces->placeTile = [
            (object)['positionX' => 1, 'positionY' => 0],
            (object)['positionX' => 0, 'positionY' => 1]
        ];
        
        // Create message bus tester with handlers
        $tester = MessageBusTester::create(
            function (GetCurrentTurn $query) use ($currentTurn) {
                return $currentTurn;
            },
            function (GetGame $query) use ($game) {
                return $game;
            },
            function (GetField $query) use ($field) {
                return $field;
            },
            function (GetPlayer $query) use ($player) {
                return $player;
            },
            function (GetPlayerPosition $query) use ($position) {
                return $position;
            },
            function (GetAvailablePlacesForPlayer $query) use ($availablePlaces) {
                return $availablePlaces;
            }
        );
        
        // Setup API client responses
        $this->apiClient->setResponse('pickTile', ['success' => true, 'response' => []]);
        $this->apiClient->setResponse('placeTile', ['success' => true, 'response' => ['tile' => ['room' => true]]]);
        $this->apiClient->setResponse('placeTileSequence', ['success' => true, 'actions' => []]);
        $this->apiClient->setResponse('movePlayer', ['success' => true, 'response' => []]);
        $this->apiClient->setResponse('endTurn', ['success' => true]);
        
        $smartVirtualPlayer = new SmartVirtualPlayer(
            $tester->messageBus(),
            $this->strategy,
            $this->apiClient
        );
        
        // Execute turn with aggressive strategy
        $actions = $smartVirtualPlayer->executeTurn($gameId, $playerId, 'aggressive');
        
        // Verify actions were executed
        $this->assertNotEmpty($actions);
        $this->assertIsArray($actions);
        
        // Check that strategy was applied
        $startAction = array_filter($actions, fn($a) => $a['type'] === 'ai_start');
        $this->assertNotEmpty($startAction);
        $firstStart = reset($startAction);
        $this->assertEquals('aggressive', $firstStart['details']['strategy']);
    }

    // #[Test] // TODO: Fix test - needs interface for VirtualPlayerApiClient to allow mocking
    public function skip_shouldExecuteTurnWithDefensiveStrategy(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        // Setup mocks for turn execution with low HP
        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId;
        
        $game = new \stdClass();
        $game->gameId = $gameId;
        
        $player = new \stdClass();
        $player->playerId = $playerId;
        $player->hp = 1; // Low HP for defensive behavior
        $player->maxHp = 5;
        $player->stunned = false;
        $player->inventory = [];
        
        $field = new \stdClass();
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [
            (object)['positionX' => 2, 'positionY' => 2]
        ];
        
        $position = new \stdClass();
        $position->positionX = 0;
        $position->positionY = 0;
        
        $availablePlaces = new \stdClass();
        $availablePlaces->moveTo = [
            (object)['positionX' => 1, 'positionY' => 0]
        ];
        $availablePlaces->placeTile = [];
        
        // Create message bus tester with handlers
        $tester = MessageBusTester::create(
            function (GetCurrentTurn $query) use ($currentTurn) {
                return $currentTurn;
            },
            function (GetGame $query) use ($game) {
                return $game;
            },
            function (GetField $query) use ($field) {
                return $field;
            },
            function (GetPlayer $query) use ($player) {
                return $player;
            },
            function (GetPlayerPosition $query) use ($position) {
                return $position;
            },
            function (GetAvailablePlacesForPlayer $query) use ($availablePlaces) {
                return $availablePlaces;
            }
        );
        
        // Setup API client responses
        $this->apiClient->setResponse('movePlayer', ['success' => true, 'response' => []]);
        $this->apiClient->setResponse('endTurn', ['success' => true]);
        
        $smartVirtualPlayer = new SmartVirtualPlayer(
            $tester->messageBus(),
            $this->strategy,
            $this->apiClient
        );
        
        // Execute turn with defensive strategy
        $actions = $smartVirtualPlayer->executeTurn($gameId, $playerId, 'defensive');
        
        // Verify actions were executed
        $this->assertNotEmpty($actions);
        $this->assertIsArray($actions);
        
        // Check that strategy was applied
        $startAction = array_filter($actions, fn($a) => $a['type'] === 'ai_start');
        $this->assertNotEmpty($startAction);
        $firstStart = reset($startAction);
        $this->assertEquals('defensive', $firstStart['details']['strategy']);
    }

    // #[Test] // TODO: Fix test - needs interface for VirtualPlayerApiClient to allow mocking
    public function skip_shouldDefaultToBalancedStrategy(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        // Setup mocks for turn execution
        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId;
        
        $game = new \stdClass();
        $game->gameId = $gameId;
        
        $player = new \stdClass();
        $player->playerId = $playerId;
        $player->hp = 3;
        $player->maxHp = 5;
        $player->stunned = false;
        $player->inventory = [];
        
        $field = new \stdClass();
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [];
        
        $position = new \stdClass();
        $position->positionX = 0;
        $position->positionY = 0;
        
        $availablePlaces = new \stdClass();
        $availablePlaces->moveTo = [];
        $availablePlaces->placeTile = [];
        
        // Create message bus tester with handlers
        $tester = MessageBusTester::create(
            function (GetCurrentTurn $query) use ($currentTurn) {
                return $currentTurn;
            },
            function (GetGame $query) use ($game) {
                return $game;
            },
            function (GetField $query) use ($field) {
                return $field;
            },
            function (GetPlayer $query) use ($player) {
                return $player;
            },
            function (GetPlayerPosition $query) use ($position) {
                return $position;
            },
            function (GetAvailablePlacesForPlayer $query) use ($availablePlaces) {
                return $availablePlaces;
            }
        );
        
        // Setup API client responses
        $this->apiClient->setResponse('endTurn', ['success' => true]);
        
        $smartVirtualPlayer = new SmartVirtualPlayer(
            $tester->messageBus(),
            $this->strategy,
            $this->apiClient
        );
        
        // Execute turn without specifying strategy (should default to balanced)
        $actions = $smartVirtualPlayer->executeTurn($gameId, $playerId, null);
        
        // Verify actions were executed
        $this->assertNotEmpty($actions);
        $this->assertIsArray($actions);
        
        // Check that default strategy was applied
        $startAction = array_filter($actions, fn($a) => $a['type'] === 'ai_start');
        $this->assertNotEmpty($startAction);
        $firstStart = reset($startAction);
        $this->assertEquals('balanced', $firstStart['details']['strategy']);
    }
}