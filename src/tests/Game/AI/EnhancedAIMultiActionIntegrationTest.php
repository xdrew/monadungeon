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
use Psr\Log\LoggerInterface;
use Telephantast\MessageBus\MessageBus;

/**
 * Integration test for Enhanced AI Player's multi-action turn system
 * Tests the complete flow of a turn with multiple actions
 */
class EnhancedAIMultiActionIntegrationTest extends TestCase
{
    private MessageBus $messageBus;
    private LoggerInterface $logger;
    private VirtualPlayerApiClient $apiClient;
    private EnhancedAIPlayer $aiPlayer;
    
    private array $actionLog = [];

    protected function setUp(): void
    {
        $this->messageBus = new MessageBus();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->apiClient = $this->createMock(VirtualPlayerApiClient::class);
        $this->actionLog = [];
        
        $this->aiPlayer = new EnhancedAIPlayer(
            $this->messageBus,
            $this->logger,
            $this->apiClient
        );
    }

    #[Test]
    public function executesFullFourActionTurn(): void
    {
        // This test simulates a complete turn where the AI:
        // 1. Places a tile
        // 2. Moves to the new tile
        // 3. Explores further
        // 4. Positions strategically
        // Then ends the turn

        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();

        // Setup game state
        $game = $this->setupGameState($gameId);
        $player = $this->setupPlayerState($playerId, 5, 5); // Full HP
        $field = $this->setupFieldState();
        $currentTurn = $this->setupTurnState($turnId, $playerId);

        // Track position changes through the turn
        $positions = [
            new FieldPlace(0, 0), // Starting position
            new FieldPlace(1, 0), // After tile placement
            new FieldPlace(2, 0), // Second move
            new FieldPlace(3, 0), // Third move
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

        // Register dynamic handlers with the message bus
        $this->messageBus->addHandler(GetGame::class, function () use ($game) {
            return $game;
        });
        $this->messageBus->addHandler(GetPlayer::class, function () use ($player) {
            return $player;
        });
        $this->messageBus->addHandler(GetField::class, function () use ($field) {
            return $field;
        });
        $this->messageBus->addHandler(GetCurrentTurn::class, function () use ($currentTurn) {
            return $currentTurn;
        });
        $this->messageBus->addHandler(GetPlayerPosition::class, function () use (&$positions, &$currentPositionIndex) {
            return $positions[$currentPositionIndex];
        });
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () use (&$availablePlacesSequence, &$availablePlacesIndex) {
            $places = $availablePlacesSequence[min($availablePlacesIndex, count($availablePlacesSequence) - 1)];
            return $places;
        });

        // Track API calls to verify multi-action behavior
        $this->apiClient->expects($this->once())
            ->method('placeTileSequence')
            ->willReturnCallback(function () use (&$currentPositionIndex, &$availablePlacesIndex) {
                $this->actionLog[] = ['type' => 'place_tile', 'action_number' => 1];
                $currentPositionIndex = 1; // Move to new tile
                $availablePlacesIndex = 1; // Update available places
                return ['success' => true, 'actions' => []];
            });

        $moveCallCount = 0;
        $this->apiClient->expects($this->exactly(3)) // Expect 3 additional moves after tile placement
            ->method('movePlayer')
            ->willReturnCallback(function ($gId, $pId, $tId, $fromX, $fromY, $toX, $toY) use (
                &$moveCallCount, 
                &$currentPositionIndex,
                &$availablePlacesIndex
            ) {
                $moveCallCount++;
                $this->actionLog[] = [
                    'type' => 'move', 
                    'action_number' => $moveCallCount + 1, // +1 because tile placement was action 1
                    'from' => "$fromX,$fromY",
                    'to' => "$toX,$toY"
                ];
                
                // Update position after each move
                $currentPositionIndex = min($moveCallCount + 1, 3);
                $availablePlacesIndex = min($moveCallCount + 1, count($availablePlacesSequence) - 1);
                
                return ['success' => true, 'response' => []];
            });

        $this->apiClient->expects($this->once())
            ->method('endTurn')
            ->willReturnCallback(function () {
                $this->actionLog[] = ['type' => 'end_turn'];
                return ['success' => true];
            });

        // Log AI decisions
        $this->logger->expects($this->any())
            ->method('info')
            ->willReturnCallback(function ($message, $context = []) {
                if (strpos($message, 'Starting AI turn') !== false) {
                    $this->actionLog[] = ['type' => 'log', 'message' => $message];
                }
            });

        // Act
        $result = $this->aiPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertTrue($result, 'AI turn should complete successfully');
        
        // Verify we executed exactly 4 actions (1 tile placement + 3 moves)
        $actionCount = count(array_filter($this->actionLog, function ($log) {
            return in_array($log['type'], ['place_tile', 'move']);
        }));
        $this->assertEquals(4, $actionCount, 'AI should perform exactly 4 actions');
        
        // Verify turn was ended
        $this->assertContains(
            ['type' => 'end_turn'],
            $this->actionLog,
            'Turn should be ended after actions'
        );
        
        // Verify action sequence
        $this->assertEquals('place_tile', $this->actionLog[0]['type'], 'First action should be tile placement');
        $this->assertEquals(1, $this->actionLog[0]['action_number']);
        
        // Verify subsequent moves
        for ($i = 1; $i <= 3; $i++) {
            $this->assertEquals('move', $this->actionLog[$i]['type'], "Action $i should be a move");
            $this->assertEquals($i + 1, $this->actionLog[$i]['action_number']);
        }
    }

    #[Test]
    public function stopsTurnEarlyWhenNoMoreBeneficialActions(): void
    {
        // Test that AI stops before 4 actions if there's nothing beneficial to do

        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();

        $game = $this->setupGameState($gameId);
        $player = $this->setupPlayerState($playerId, 5, 5);
        $field = $this->setupFieldState();
        $currentTurn = $this->setupTurnState($turnId, $playerId);

        $currentPosition = new FieldPlace(0, 0);

        // Limited options - only one tile placement, no good moves after
        $limitedPlaces = $this->createAvailablePlaces(
            [new FieldPlace(1, 0)], // Can place one tile
            [] // No moves available after
        );

        // Register handlers with the message bus
        $this->messageBus->addHandler(GetGame::class, function () use ($game) {
            return $game;
        });
        $this->messageBus->addHandler(GetPlayer::class, function () use ($player) {
            return $player;
        });
        $this->messageBus->addHandler(GetField::class, function () use ($field) {
            return $field;
        });
        $this->messageBus->addHandler(GetCurrentTurn::class, function () use ($currentTurn) {
            return $currentTurn;
        });
        $this->messageBus->addHandler(GetPlayerPosition::class, function () use ($currentPosition) {
            return $currentPosition;
        });
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () use ($limitedPlaces) {
            return $limitedPlaces;
        });

        // Only tile placement should happen
        $this->apiClient->expects($this->once())
            ->method('placeTileSequence')
            ->willReturnCallback(function () {
                $this->actionLog[] = ['type' => 'place_tile'];
                return ['success' => true, 'actions' => []];
            });

        // No moves should be attempted
        $this->apiClient->expects($this->never())
            ->method('movePlayer');

        $this->apiClient->expects($this->once())
            ->method('endTurn')
            ->willReturn(['success' => true]);

        // Act
        $result = $this->aiPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertTrue($result);
        $this->assertCount(1, array_filter($this->actionLog, fn($log) => $log['type'] === 'place_tile'));
        $this->assertCount(0, array_filter($this->actionLog, fn($log) => $log['type'] === 'move'));
    }

    #[Test]
    public function handlesTurnEndingActionsCorrectly(): void
    {
        // Test that certain actions (like healing or picking items) end the turn immediately

        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();

        $game = $this->setupGameState($gameId);
        $player = $this->setupPlayerState($playerId, 2, 5); // Low HP
        $field = $this->setupFieldStateWithHealing();
        $currentTurn = $this->setupTurnState($turnId, $playerId);

        $currentPosition = new FieldPlace(1, 1);
        $healingPosition = new FieldPlace(2, 2);

        // Can move to healing fountain
        $availablePlaces = $this->createAvailablePlaces(
            [],
            [$healingPosition]
        );

        // Register handlers with the message bus
        $this->messageBus->addHandler(GetGame::class, function () use ($game) {
            return $game;
        });
        $this->messageBus->addHandler(GetPlayer::class, function () use ($player) {
            return $player;
        });
        $this->messageBus->addHandler(GetField::class, function () use ($field) {
            return $field;
        });
        $this->messageBus->addHandler(GetCurrentTurn::class, function () use ($currentTurn) {
            return $currentTurn;
        });
        $this->messageBus->addHandler(GetPlayerPosition::class, function () use ($currentPosition) {
            return $currentPosition;
        });
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () use ($availablePlaces) {
            return $availablePlaces;
        });

        // Should move to healing fountain (priority due to low HP)
        $this->apiClient->expects($this->once())
            ->method('movePlayer')
            ->with(
                $gameId,
                $playerId,
                $turnId,
                1, 1, // from
                2, 2, // to healing
                false
            )
            ->willReturnCallback(function () {
                $this->actionLog[] = ['type' => 'move_to_heal'];
                return ['success' => true];
            });

        // Turn should end immediately after healing
        $this->apiClient->expects($this->once())
            ->method('endTurn')
            ->willReturnCallback(function () {
                $this->actionLog[] = ['type' => 'end_turn'];
                return ['success' => true];
            });

        // No other actions should be attempted
        $this->apiClient->expects($this->never())
            ->method('placeTileSequence');

        // Act
        $result = $this->aiPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertTrue($result);
        $this->assertEquals(2, count($this->actionLog)); // Only heal move and end turn
        $this->assertEquals('move_to_heal', $this->actionLog[0]['type']);
        $this->assertEquals('end_turn', $this->actionLog[1]['type']);
    }

    // Helper methods

    private function setupGameState(Uuid $gameId): Game
    {
        $game = $this->createMock(Game::class);
        $game->gameId = $gameId;
        return $game;
    }

    private function setupPlayerState(Uuid $playerId, int $hp, int $maxHp): Player
    {
        $player = $this->createMock(Player::class);
        $player->playerId = $playerId;
        $player->hp = $hp;
        $player->maxHp = $maxHp;
        return $player;
    }

    private function setupFieldState(): Field
    {
        $field = $this->createMock(Field::class);
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [];
        $field->size = ['minX' => -5, 'maxX' => 5, 'minY' => -5, 'maxY' => 5];
        return $field;
    }

    private function setupFieldStateWithHealing(): Field
    {
        $field = $this->createMock(Field::class);
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = ['2,2']; // Healing at 2,2
        $field->size = ['minX' => -5, 'maxX' => 5, 'minY' => -5, 'maxY' => 5];
        return $field;
    }

    private function setupTurnState(Uuid $turnId, Uuid $playerId): \stdClass
    {
        $turn = new \stdClass();
        $turn->turnId = $turnId;
        $turn->playerId = $playerId;
        return $turn;
    }

    private function createAvailablePlaces(array $placeTile, array $moveTo): \stdClass
    {
        $places = new \stdClass();
        $places->placeTile = $placeTile;
        $places->moveTo = $moveTo;
        return $places;
    }
}