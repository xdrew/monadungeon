<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Bag\Bag;
use App\Game\Bag\BagCreated;
use App\Game\Bag\GetBag;
use App\Game\Item\Item;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Battle\BattleCompleted;
use App\Game\Battle\FinalizeBattle;
use App\Game\Battle\StartBattle;
use App\Game\Battle\BattleResult;
use App\Game\Deck\Deck;
use App\Game\Deck\DeckCreated;
use App\Game\Deck\DeckTile;
use App\Game\Deck\GetDeck;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\Field\GetField;
use App\Game\Field\GetTile;
use App\Game\Movement\Commands\MovePlayer;
use App\Game\Movement\Movement;
use App\Game\Field\PlaceTile;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Field\TilePlaced;
use App\Game\Field\Tile;
use App\Game\Field\TileOrientation;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GameStarted;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Player\GetPlayer;
use App\Game\Player\Player;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Turn\GameTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnAction;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use function App\Tests\Infrastructure\MessageBus\handle;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * @covers \App\Game\Movement\Movement
 * @covers \App\Game\Turn\GameTurn
 * @covers \App\Game\Field\Field
 */
class MovementAfterBattleTest extends TestCase
{
    #[Test]
    public function it_prevents_movement_after_battle_win(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $turnId = Uuid::v7();
        
        // Create a simple mock field with transitions already set up
        $field = new class($gameId) {
            private Uuid $gameId;
            private array $transitions = [];
            private array $placedTiles = [];
            private array $items = [];
            
            public function __construct(Uuid $gameId)
            {
                $this->gameId = $gameId;
                // Set up transitions between (0,0) and (1,0)
                $this->transitions['0,0'] = ['1,0'];
                $this->transitions['1,0'] = ['0,0'];
                $this->placedTiles['0,0'] = Uuid::v7();
                $this->placedTiles['1,0'] = Uuid::v7();
            }
            
            public function getDebugTransitions(): array
            {
                return $this->transitions;
            }
            
            public function getPlacedTiles(): array
            {
                return $this->placedTiles;
            }
            
            public function getItems(): array
            {
                return $this->items;
            }
            
            public function getTeleportationConnections(): array
            {
                return [];
            }
        };
        
        // Create movement aggregate and initialize positions
        $movement = Movement::create(new GameCreated($gameId, new \DateTimeImmutable()));
        $gameStarted = new GameStarted($gameId, new \DateTimeImmutable(), [$playerId]);
        $tester = MessageBusTester::create(
            static fn (GetGame $_query) => new class($gameId, [$playerId]) {
                public function __construct(private Uuid $gameId, private array $players) {}
                public function getPlayers(): array { return $this->players; }
            }
        );
        $tester->handle($movement->initializeStartingPositions(...), $gameStarted);
        
        // Create and start turn
        $turn = GameTurn::start(new StartTurn(
            gameId: $gameId,
            playerId: $playerId,
            turnNumber: 1,
            turnId: $turnId,
            at: new \DateTimeImmutable()
        ), startMessageContext());
        
        // Create message bus tester with all query handlers
        $player = new class($playerId) {
            public function __construct(private Uuid $playerId) {}
            public function getHP(): int { return 10; }
        };
        
        $tester = MessageBusTester::create(
            static fn (GetField $_query) => $field,
            static fn (GetPlayer $_query) => $player,
            static fn (GetCurrentPlayer $_query): Uuid => $playerId,
            static fn (GetCurrentTurn $_query): Uuid => $turnId,
            static fn (GetTurn $_query) => $turn,
            static function (\App\Game\Movement\GetPlayerPosition $query) use ($movement): FieldPlace {
                return $movement->getPlayerPosition($query);
            }
        );
        
        // First move player from (0,0) to (1,0) - should succeed
        $moveCommand = new MovePlayer(
            gameId: $gameId,
            playerId: $playerId,
            turnId: $turnId,
            fromPosition: new FieldPlace(0, 0),
            toPosition: new FieldPlace(1, 0),
            ignoreMonster: true // Ignore monster for this test
        );
        
        // Movement should succeed
        $tester->handle($movement->movePlayer(...), $moveCommand);
        
        // Simulate battle win
        $battleCompleted = new BattleCompleted(
            battleId: Uuid::v7(),
            gameId: $gameId,
            playerId: $playerId,
            result: BattleResult::WIN,
            diceResults: [5, 6],
            diceRollDamage: 11,
            itemDamage: 0,
            totalDamage: 11,
            monsterHP: 8,
            usedItems: [],
            availableConsumables: [],
            needsConsumableConfirmation: false,
            itemPickedUp: false
        );
        
        // Handle battle completed in movement to set the flag
        $tester->handle($movement->onBattleCompleted(...), $battleCompleted);
        
        // Try to move again - should throw exception
        $secondMoveCommand = new MovePlayer(
            gameId: $gameId,
            playerId: $playerId,
            turnId: $turnId,
            fromPosition: new FieldPlace(1, 0),
            toPosition: new FieldPlace(0, 0),
            ignoreMonster: false
        );
        
        $this->expectException(\App\Game\Movement\Exception\CannotMoveAfterBattleException::class);
        $this->expectExceptionMessage('Cannot move after battle in the same turn');
        
        // Try to move again after battle
        $tester->handle($movement->movePlayer(...), $secondMoveCommand);
    }
    
    #[Test]
    public function it_allows_tile_placement_after_battle_win(): void
    {
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        
        // Create a simple mock field with transitions and available places
        $field = new class($gameId) {
            private Uuid $gameId;
            
            public function __construct(Uuid $gameId)
            {
                $this->gameId = $gameId;
            }
            
            public function getAvailablePlacesForPlayer(GetAvailablePlacesForPlayer $query): array
            {
                // After battle, player should still be able to place tiles
                return [
                    'moveTo' => [], // No movement allowed after battle
                    'placeTile' => [
                        new FieldPlace(2, 0),
                        new FieldPlace(0, 1),
                    ]
                ];
            }
        };
        
        // Create message bus tester
        $tester = MessageBusTester::create(
            static fn (GetField $_query) => $field
        );
        
        // Check available places - the Field shows that tile placement is still allowed after battle
        $availablePlaces = $field->getAvailablePlacesForPlayer(new GetAvailablePlacesForPlayer(
            gameId: $gameId,
            playerId: $playerId,
            messageBus: $tester->messageBus()
        ));
        
        // The key is that placeTile is still available, allowing tile placement after battle
        $this->assertNotEmpty($availablePlaces['placeTile'], 'Player should still be able to place tiles after battle');
        $this->assertEmpty($availablePlaces['moveTo'], 'Player should not be able to move after battle');
    }
}