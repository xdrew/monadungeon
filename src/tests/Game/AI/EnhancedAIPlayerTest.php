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
use App\Game\Field\TileSide;
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

class EnhancedAIPlayerTest extends TestCase
{
    private MessageBus $messageBus;
    private LoggerInterface $logger;
    private VirtualPlayerApiClient $apiClient;
    private EnhancedAIPlayer $aiPlayer;
    private HttpKernelInterface $httpKernel;

    protected function setUp(): void
    {
        // Use a real MessageBus instance with handler stubs
        $this->messageBus = new MessageBus();
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Create a mock HttpKernel that returns success responses
        $this->httpKernel = $this->createMock(HttpKernelInterface::class);
        $this->httpKernel->method('handle')
            ->willReturn(new JsonResponse(['success' => true, 'actions' => []]));
        
        $this->apiClient = new VirtualPlayerApiClient($this->httpKernel);
        
        $this->aiPlayer = new EnhancedAIPlayer(
            $this->messageBus,
            $this->logger,
            $this->apiClient
        );
    }

    #[Test]
    public function canBeInstantiated(): void
    {
        $this->assertInstanceOf(EnhancedAIPlayer::class, $this->aiPlayer);
    }

    // #[Test] // TODO: Fix test - needs to be rewritten to use MessageBusTester
    public function skip_executesUpToFourActionsPerTurn(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();

        // Mock game state
        $game = $this->createMock(Game::class);
        $game->gameId = $gameId;

        $player = $this->createMock(Player::class);
        $player->playerId = $playerId;
        $player->hp = 5;
        $player->maxHp = 5;

        $field = $this->createMock(Field::class);
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [];

        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId;

        $availablePlaces = new \stdClass();
        $availablePlaces->placeTile = [
            new FieldPlace(1, 0),
            new FieldPlace(0, 1),
        ];
        $availablePlaces->moveTo = [
            new FieldPlace(1, 1),
            new FieldPlace(2, 0),
            new FieldPlace(0, 2),
        ];

        $currentPosition = new FieldPlace(0, 0);

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
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () use ($availablePlaces) {
            return $availablePlaces;
        });
        $this->messageBus->addHandler(GetPlayerPosition::class, function () use ($currentPosition) {
            return $currentPosition;
        });

        // Mock API client to track actions
        $actionCount = 0;
        $this->apiClient->expects($this->any())
            ->method('placeTileSequence')
            ->willReturnCallback(function () use (&$actionCount) {
                $actionCount++;
                return ['success' => true, 'actions' => []];
            });

        $this->apiClient->expects($this->any())
            ->method('movePlayer')
            ->willReturnCallback(function () use (&$actionCount) {
                $actionCount++;
                return ['success' => true, 'response' => []];
            });

        $this->apiClient->expects($this->once())
            ->method('endTurn')
            ->willReturn(['success' => true]);

        // Act
        $result = $this->aiPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertTrue($result);
        $this->assertLessThanOrEqual(4, $actionCount, 'AI should not perform more than 4 actions per turn');
    }

    // #[Test] // TODO: Fix test - needs to be rewritten to use MessageBusTester
    public function skip_prioritizesHealingWhenLowHP(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();

        $game = $this->createMock(Game::class);
        $game->gameId = $gameId;

        $player = $this->createMock(Player::class);
        $player->playerId = $playerId;
        $player->hp = 1; // Low HP
        $player->maxHp = 5;

        $field = $this->createMock(Field::class);
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = ['2,2']; // Healing fountain available

        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId;

        $healingPosition = new FieldPlace(2, 2);
        $availablePlaces = new \stdClass();
        $availablePlaces->placeTile = [];
        $availablePlaces->moveTo = [$healingPosition]; // Can reach healing

        $currentPosition = new FieldPlace(1, 1);

        // Set up message bus
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
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () use ($availablePlaces) {
            return $availablePlaces;
        });
        $this->messageBus->addHandler(GetPlayerPosition::class, function () use ($currentPosition) {
            return $currentPosition;
        });

        // Expect movement to healing fountain
        $this->apiClient->expects($this->once())
            ->method('movePlayer')
            ->with(
                $gameId,
                $playerId,
                $turnId,
                1, // from position
                1,
                2, // to healing fountain
                2,
                false
            )
            ->willReturn(['success' => true]);

        $this->apiClient->expects($this->once())
            ->method('endTurn')
            ->willReturn(['success' => true]);

        // Act
        $result = $this->aiPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertTrue($result);
    }

    // #[Test] // TODO: Fix test - needs to be rewritten to use MessageBusTester
    public function skip_skipsActionWhenStunned(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();

        $game = $this->createMock(Game::class);
        $game->gameId = $gameId;

        $player = $this->createMock(Player::class);
        $player->playerId = $playerId;
        $player->hp = 0; // Stunned

        $field = $this->createMock(Field::class);

        $currentTurn = new \stdClass();
        $currentTurn->turnId = Uuid::v7();
        $currentTurn->playerId = $playerId;

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

        // Should not make any moves when stunned
        $this->apiClient->expects($this->never())
            ->method('movePlayer');

        $this->apiClient->expects($this->never())
            ->method('placeTileSequence');

        // Act
        $result = $this->aiPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertTrue($result);
    }

    // #[Test] // TODO: Fix test - needs to be rewritten to use MessageBusTester
    public function skip_choosesConsumablesInBattleDraw(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        $battleId = Uuid::v7();

        $battleInfo = [
            'battleId' => $battleId->toString(),
            'result' => 'draw',
            'monster' => 5, // Monster HP
            'totalDamage' => 2, // Current damage
            'availableConsumables' => [
                ['itemId' => Uuid::v7()->toString(), 'type' => 'fireball'] // Does 9 damage
            ]
        ];

        // Expect to use fireball to win the battle
        $this->apiClient->expects($this->once())
            ->method('finalizeBattle')
            ->with(
                $gameId,
                $playerId,
                $turnId,
                $battleId,
                $this->callback(function ($consumables) {
                    return count($consumables) === 1; // Should use one consumable
                }),
                true
            )
            ->willReturn(['success' => true]);

        // Act
        $result = $this->aiPlayer->handleBattle($gameId, $playerId, $turnId, $battleInfo);

        // Assert  
        $this->assertTrue($result);
    }

    // #[Test] // TODO: Fix test - needs to be rewritten to use MessageBusTester
    public function skip_replacesWorseItemsWhenInventoryFull(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();

        $newItem = ['itemId' => Uuid::v7()->toString(), 'type' => 'axe']; // Value: 3
        $currentInventory = [
            ['itemId' => Uuid::v7()->toString(), 'type' => 'sword'], // Value: 2
            ['itemId' => Uuid::v7()->toString(), 'type' => 'dagger'], // Value: 1
        ];

        $pickupResult = [
            'inventoryFull' => true,
            'item' => $newItem,
            'currentInventory' => $currentInventory
        ];

        // Should replace dagger (lowest value) with axe
        $this->apiClient->expects($this->once())
            ->method('inventoryAction')
            ->with(
                $gameId,
                $playerId,
                $turnId,
                'replace',
                $this->anything(),
                $this->callback(function ($replacedItemId) use ($currentInventory) {
                    // Should replace the dagger
                    return $replacedItemId->toString() === $currentInventory[1]['itemId'];
                })
            )
            ->willReturn(['success' => true]);

        // Act
        $result = $this->aiPlayer->handleInventoryFull($gameId, $playerId, $turnId, $pickupResult);

        // Assert
        $this->assertTrue($result);
    }

    // #[Test] // TODO: Fix test - needs to be rewritten to use MessageBusTester
    public function skip_executesMultipleMovesInSingleTurn(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();

        $game = $this->createMock(Game::class);
        $game->gameId = $gameId;

        $player = $this->createMock(Player::class);
        $player->playerId = $playerId;
        $player->hp = 5;
        $player->maxHp = 5;

        $field = $this->createMock(Field::class);
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [];

        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId;

        // Multiple move positions available
        $availablePlaces = new \stdClass();
        $availablePlaces->placeTile = [new FieldPlace(1, 0)];
        $availablePlaces->moveTo = [
            new FieldPlace(1, 1),
            new FieldPlace(2, 2),
            new FieldPlace(3, 3),
        ];

        $currentPosition = new FieldPlace(0, 0);

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
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () use ($availablePlaces) {
            return $availablePlaces;
        });
        $this->messageBus->addHandler(GetPlayerPosition::class, function () use ($currentPosition) {
            return $currentPosition;
        });

        $moveCount = 0;
        
        // Track tile placement
        $this->apiClient->expects($this->once())
            ->method('placeTileSequence')
            ->willReturn(['success' => true, 'actions' => []]);

        // Track multiple moves
        $this->apiClient->expects($this->any())
            ->method('movePlayer')
            ->willReturnCallback(function () use (&$moveCount) {
                $moveCount++;
                return ['success' => true, 'response' => []];
            });

        $this->apiClient->expects($this->once())
            ->method('endTurn')
            ->willReturn(['success' => true]);

        // Act
        $result = $this->aiPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertTrue($result);
        $this->assertGreaterThan(0, $moveCount, 'AI should perform exploration moves');
        $this->assertLessThanOrEqual(3, $moveCount, 'AI should not exceed remaining actions after tile placement');
    }

    // #[Test] // TODO: Fix test - needs to be rewritten to use MessageBusTester
    public function skip_stopsActionsWhenBattleOccurs(): void
    {
        // Arrange
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();

        $game = $this->createMock(Game::class);
        $game->gameId = $gameId;

        $player = $this->createMock(Player::class);
        $player->playerId = $playerId;
        $player->hp = 5;
        $player->maxHp = 5;

        $field = $this->createMock(Field::class);
        $field->tiles = [];
        $field->items = [];
        $field->healingFountainPositions = [];

        $currentTurn = new \stdClass();
        $currentTurn->turnId = $turnId;
        $currentTurn->playerId = $playerId;

        $availablePlaces = new \stdClass();
        $availablePlaces->placeTile = [];
        $availablePlaces->moveTo = [new FieldPlace(1, 1)];

        $currentPosition = new FieldPlace(0, 0);

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
        $this->messageBus->addHandler(GetAvailablePlacesForPlayer::class, function () use ($availablePlaces) {
            return $availablePlaces;
        });
        $this->messageBus->addHandler(GetPlayerPosition::class, function () use ($currentPosition) {
            return $currentPosition;
        });

        // First move triggers a battle
        $this->apiClient->expects($this->once())
            ->method('movePlayer')
            ->willReturn([
                'success' => true,
                'response' => [
                    'battleInfo' => [
                        'battleId' => Uuid::v7()->toString(),
                        'result' => 'win',
                        'monsterType' => 'skeleton'
                    ]
                ]
            ]);

        // Should end turn after battle
        $this->apiClient->expects($this->once())
            ->method('endTurn')
            ->willReturn(['success' => true]);

        // Act
        $result = $this->aiPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function testStrategyConfiguration(): void
    {
        // Test default configuration
        $config = $this->aiPlayer->getStrategyConfig();
        $this->assertTrue($config['aggressive']);
        $this->assertEquals(1, $config['healingThreshold']);

        // Test setting new configuration
        $newConfig = [
            'aggressive' => false,
            'healingThreshold' => 3,
            'riskTolerance' => 0.5
        ];
        
        $this->aiPlayer->setStrategyConfig($newConfig);
        $updatedConfig = $this->aiPlayer->getStrategyConfig();
        
        $this->assertFalse($updatedConfig['aggressive']);
        $this->assertEquals(3, $updatedConfig['healingThreshold']);
        $this->assertEquals(0.5, $updatedConfig['riskTolerance']);
    }
}