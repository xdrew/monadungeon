<?php

declare(strict_types=1);

namespace App\Tests\Game\GameLifecycle;

use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemType;
use App\Game\Item\ItemName;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\EndGame;
use App\Game\GameLifecycle\Error\CannotAddPlayerToAlreadyFullGame;
use App\Game\GameLifecycle\Error\CannotAddPlayerToAlreadyPreparedGame;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GameEnded;
use App\Game\GameLifecycle\GameStarted;
use App\Game\GameLifecycle\GameStatus;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\NextTurn;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\GameLifecycle\StartGame;
use App\Game\GameLifecycle\TurnChanged;
use App\Game\Player\AddItemToInventory;
use App\Game\Player\GetActivePlayers;
use App\Game\Player\GetPlayer;
use App\Game\Player\ItemAddedToInventory;
use App\Game\Player\Player;
use App\Game\Player\ResetPlayerHP;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnEnded;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

#[CoversClass(Game::class)]
final class GameTest extends TestCase
{
    private Uuid $gameId;
    private Uuid $player1Id;
    private Uuid $player2Id;
    private Uuid $player3Id;
    private Uuid $player4Id;
    private Uuid $player5Id;
    private \DateTimeImmutable $fixedTime;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->player1Id = Uuid::v7();
        $this->player2Id = Uuid::v7();
        $this->player3Id = Uuid::v7();
        $this->player4Id = Uuid::v7();
        $this->player5Id = Uuid::v7();
        $this->fixedTime = new \DateTimeImmutable('2024-01-01 12:00:00');
    }

    #[Test]
    public function itCreatesGame(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );

        [$game, $messages] = handle(Game::create(...), $createGame);

        self::assertInstanceOf(Game::class, $game);
        self::assertEquals($this->gameId, $game->getGameId());
        self::assertEquals(GameStatus::LOBBY, $game->getStatus());
        self::assertEquals([], $game->getPlayers());
        self::assertNull($game->getCurrentPlayerId());
        self::assertEquals(0, $game->getCurrentTurnNumber());
        self::assertNull($game->getCurrentTurnId());

        self::assertEquals(
            [new GameCreated($this->gameId, $this->fixedTime, 88)],
            $messages,
        );
    }

    #[Test]
    public function itAddsPlayer(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $addPlayer = new AddPlayer(
            gameId: $this->gameId,
            playerId: $this->player1Id,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($game->addPlayer(...), $addPlayer);

        self::assertEquals([$this->player1Id], $game->getPlayers());
        self::assertEquals(
            [new PlayerAdded($this->gameId, $this->player1Id)],
            $messages,
        );
    }

    #[Test]
    public function itAddsMultiplePlayers(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Add first player
        $addPlayer1 = new AddPlayer($this->gameId, $this->player1Id);
        $tester->handle($game->addPlayer(...), $addPlayer1);

        // Add second player
        $addPlayer2 = new AddPlayer($this->gameId, $this->player2Id);
        $tester->handle($game->addPlayer(...), $addPlayer2);

        self::assertEquals([$this->player1Id, $this->player2Id], $game->getPlayers());
    }

    #[Test]
    public function itPreventsDuplicatePlayers(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Add player twice
        $addPlayer = new AddPlayer($this->gameId, $this->player1Id);
        $tester->handle($game->addPlayer(...), $addPlayer);
        $tester->handle($game->addPlayer(...), $addPlayer);

        // Should only be added once
        self::assertEquals([$this->player1Id], $game->getPlayers());
    }

    #[Test]
    public function itThrowsExceptionWhenAddingTooManyPlayers(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Add 4 players (maximum)
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player2Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player3Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player4Id));

        // Try to add 5th player
        $this->expectException(CannotAddPlayerToAlreadyFullGame::class);
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player5Id));
    }

    #[Test]
    public function itThrowsExceptionWhenAddingPlayerAfterGameStarted(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Add player and start game
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->start(...), new StartGame($this->gameId, $this->fixedTime));

        // Try to add another player after game started
        $this->expectException(CannotAddPlayerToAlreadyPreparedGame::class);
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player2Id));
    }

    #[Test]
    public function itStartsGame(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Add players
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player2Id));

        $startGame = new StartGame($this->gameId, $this->fixedTime);
        [, $messages] = $tester->handle($game->start(...), $startGame);

        self::assertEquals(GameStatus::STARTED, $game->getStatus());
        self::assertEquals($this->player1Id, $game->getCurrentPlayerId());
        self::assertEquals(1, $game->getCurrentTurnNumber());
        self::assertNotNull($game->getCurrentTurnId());

        // Should dispatch GameStarted, TurnChanged, and StartTurn events
        self::assertCount(3, $messages);
        self::assertInstanceOf(GameStarted::class, $messages[0]);
        self::assertInstanceOf(TurnChanged::class, $messages[1]);
        self::assertInstanceOf(StartTurn::class, $messages[2]);

        self::assertEquals($this->gameId, $messages[0]->gameId);
        self::assertEquals($this->fixedTime, $messages[0]->gameStartTime);
    }

    #[Test]
    public function itIgnoresStartGameWhenNotInLobby(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Add player and start game
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->start(...), new StartGame($this->gameId, $this->fixedTime));

        // Try to start again
        [, $messages] = $tester->handle($game->start(...), new StartGame($this->gameId, $this->fixedTime));

        // Should not dispatch any events
        self::assertEquals([], $messages);
    }

    #[Test]
    public function itIgnoresStartGameWithNoPlayers(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();
        $startGame = new StartGame($this->gameId, $this->fixedTime);
        [, $messages] = $tester->handle($game->start(...), $startGame);

        // Should not change status or dispatch events
        self::assertEquals(GameStatus::LOBBY, $game->getStatus());
        self::assertEquals([], $messages);
    }

    #[Test]
    public function itHandlesTurnEnded(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $turnId = Uuid::v7();
        $turnEnded = new TurnEnded($turnId, $this->gameId, $this->player1Id, $this->fixedTime);

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($game->turnEnded(...), $turnEnded);

        // Should dispatch NextTurn event
        self::assertCount(1, $messages);
        self::assertInstanceOf(NextTurn::class, $messages[0]);
        self::assertEquals($this->gameId, $messages[0]->gameId);
    }

    #[Test]
    public function itAdvancesToNextTurn(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Add players and start game
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player2Id));
        $tester->handle($game->start(...), new StartGame($this->gameId, $this->fixedTime));

        self::assertEquals($this->player1Id, $game->getCurrentPlayerId());
        self::assertEquals(1, $game->getCurrentTurnNumber());

        // Create a real player that is not defeated
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->player2Id,
        );
        [$realPlayer, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $tester2 = MessageBusTester::create(
            static function (GetPlayer $query) use ($realPlayer): Player {
                return $realPlayer;
            },
        );

        // Advance to next turn
        $nextTurn = new NextTurn($this->gameId, $this->fixedTime);
        [, $messages] = $tester2->handle($game->nextTurn(...), $nextTurn);

        self::assertEquals($this->player2Id, $game->getCurrentPlayerId());
        self::assertEquals(2, $game->getCurrentTurnNumber());
        self::assertEquals(GameStatus::TURN_IN_PROGRESS, $game->getStatus());

        // Should dispatch TurnChanged and StartTurn events
        self::assertCount(2, $messages);
        self::assertInstanceOf(TurnChanged::class, $messages[0]);
        self::assertInstanceOf(StartTurn::class, $messages[1]);
    }

    #[Test]
    public function itHandlesStunnedPlayerTurn(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Add players and start game
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player2Id));
        $tester->handle($game->start(...), new StartGame($this->gameId, $this->fixedTime));

        // Create a real stunned player (HP = 0)
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->player2Id,
            hp: 0, // Create stunned player
        );
        [$stunnedPlayer, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $tester2 = MessageBusTester::create(
            static function (GetPlayer $query) use ($stunnedPlayer): Player {
                return $stunnedPlayer;
            },
        );

        // Advance to next turn with stunned player
        $nextTurn = new NextTurn($this->gameId, $this->fixedTime);
        [, $messages] = $tester2->handle($game->nextTurn(...), $nextTurn);

        // Should dispatch TurnChanged, ResetPlayerHP, StartTurn, and EndTurn events
        self::assertCount(4, $messages);
        self::assertInstanceOf(TurnChanged::class, $messages[0]);
        self::assertInstanceOf(ResetPlayerHP::class, $messages[1]);
        self::assertInstanceOf(StartTurn::class, $messages[2]);
        self::assertInstanceOf(EndTurn::class, $messages[3]);

        // Check ResetPlayerHP event
        self::assertEquals($this->player2Id, $messages[1]->playerId);
        self::assertEquals($this->gameId, $messages[1]->gameId);
    }

    #[Test]
    public function itIgnoresNextTurnWhenGameNotInProgress(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();
        $nextTurn = new NextTurn($this->gameId, $this->fixedTime);
        [, $messages] = $tester->handle($game->nextTurn(...), $nextTurn);

        // Should not dispatch any events when game is in lobby
        self::assertEquals([], $messages);
    }

    #[Test]
    public function itIgnoresNextTurnWithNoPlayers(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Start game without players
        $tester->handle($game->start(...), new StartGame($this->gameId, $this->fixedTime));

        // Manually set status to allow nextTurn
        $reflection = new \ReflectionClass($game);
        $statusProperty = $reflection->getProperty('status');
        $statusProperty->setAccessible(true);
        $statusProperty->setValue($game, GameStatus::STARTED);

        $nextTurn = new NextTurn($this->gameId, $this->fixedTime);
        [, $messages] = $tester->handle($game->nextTurn(...), $nextTurn);

        // Should not dispatch any events when no players
        self::assertEquals([], $messages);
    }

    #[Test]
    public function itCyclesThroughAllPlayers(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();

        // Add 3 players and start game
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player2Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player3Id));
        $tester->handle($game->start(...), new StartGame($this->gameId, $this->fixedTime));

        // Create a real healthy player
        $playerAdded = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->player1Id, // Any player ID is fine for cycling test
        );
        [$healthyPlayer, ] = handle(Player::onPlayerAddedToGame(...), $playerAdded);

        $tester2 = MessageBusTester::create(
            static function (GetPlayer $query) use ($healthyPlayer): Player {
                return $healthyPlayer;
            },
        );

        // Start with player1
        self::assertEquals($this->player1Id, $game->getCurrentPlayerId());

        // Advance to player2
        $tester2->handle($game->nextTurn(...), new NextTurn($this->gameId, $this->fixedTime));
        self::assertEquals($this->player2Id, $game->getCurrentPlayerId());

        // Advance to player3
        $tester2->handle($game->nextTurn(...), new NextTurn($this->gameId, $this->fixedTime));
        self::assertEquals($this->player3Id, $game->getCurrentPlayerId());

        // Cycle back to player1
        $tester2->handle($game->nextTurn(...), new NextTurn($this->gameId, $this->fixedTime));
        self::assertEquals($this->player1Id, $game->getCurrentPlayerId());
    }

    #[Test]
    public function itEndsGameWhenPlayerPicksGameEndingItem(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        // Create game-ending item (Dragon with Ruby Chest)
        $gameEndingItem = new Item(
            name: ItemName::DRAGON,
            type: ItemType::RUBY_CHEST,
        );

        $itemPickedEvent = new ItemAddedToInventory(
            gameId: $this->gameId,
            playerId: $this->player1Id,
            item: $gameEndingItem,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($game->onItemPicked(...), $itemPickedEvent);

        self::assertCount(1, $messages);
        self::assertInstanceOf(EndGame::class, $messages[0]);
        self::assertEquals($this->gameId, $messages[0]->gameId);
    }

    #[Test]
    public function itIgnoresNonGameEndingItems(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        // Create regular item
        $regularItem = new Item(
            name: ItemName::SKELETON_WARRIOR,
            type: ItemType::SWORD,
        );

        $itemPickedEvent = new ItemAddedToInventory(
            gameId: $this->gameId,
            playerId: $this->player1Id,
            item: $regularItem,
        );

        $tester = MessageBusTester::create();
        [, $messages] = $tester->handle($game->onItemPicked(...), $itemPickedEvent);

        // Should not dispatch EndGame event
        self::assertEquals([], $messages);
    }

    #[Test]
    public function itEndsGameWithScoreCalculation(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        // Add players to the game
        $tester = MessageBusTester::create();
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player2Id));

        // Create real players with different treasure scores
        $player1Added = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->player1Id,
        );
        [$player1, ] = handle(Player::onPlayerAddedToGame(...), $player1Added);
        
        $player2Added = new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->player2Id,
        );
        [$player2, ] = handle(Player::onPlayerAddedToGame(...), $player2Added);

        // Add treasures to player1 (5 points total)
        $tester = MessageBusTester::create();
        $treasure1 = new Item(ItemName::SKELETON_WARRIOR, ItemType::CHEST); // 2 points
        $treasure2 = new Item(ItemName::DRAGON, ItemType::RUBY_CHEST); // 3 points
        $tester->handle($player1->addItem(...), new AddItemToInventory($this->gameId, $this->player1Id, $treasure1));
        $tester->handle($player1->addItem(...), new AddItemToInventory($this->gameId, $this->player1Id, $treasure2));

        // Add treasure to player2 (2 points total)
        $treasure3 = new Item(ItemName::SKELETON_KING, ItemType::CHEST); // 2 points
        $tester->handle($player2->addItem(...), new AddItemToInventory($this->gameId, $this->player2Id, $treasure3));

        $player1Id = $this->player1Id;
        $player2Id = $this->player2Id;
        
        $testTester = MessageBusTester::create(
            static function (GetActivePlayers $query) use ($player1Id, $player2Id): array {
                return [$player1Id, $player2Id];
            },
            static function (GetPlayer $query) use ($player1, $player2, $player1Id): Player {
                return $query->playerId->equals($player1Id) ? $player1 : $player2;
            },
        );

        $endGame = new EndGame($this->gameId);
        [, $messages] = $testTester->handle($game->endGame(...), $endGame);

        self::assertEquals(GameStatus::FINISHED, $game->getStatus());

        // Should dispatch GameEnded event with correct scores
        self::assertCount(1, $messages);
        self::assertInstanceOf(GameEnded::class, $messages[0]);

        $gameEndedEvent = $messages[0];
        self::assertEquals($this->gameId, $gameEndedEvent->gameId);
        self::assertEquals($this->player1Id, $gameEndedEvent->winnerId); // Player1 has higher score

        // Check scores (player1: 5 points, player2: 2 points)
        $expectedScores = [
            $this->player1Id->toString() => 5,
            $this->player2Id->toString() => 2,
        ];
        self::assertEquals($expectedScores, $gameEndedEvent->scores);
    }

    #[Test]
    public function itReturnsCurrentPlayer(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->start(...), new StartGame($this->gameId, $this->fixedTime));

        $getCurrentPlayer = new GetCurrentPlayer($this->gameId);
        $currentPlayerId = $game->getCurrentPlayerTurn($getCurrentPlayer);

        self::assertEquals($this->player1Id, $currentPlayerId);
    }

    #[Test]
    public function itReturnsNullCurrentPlayerWhenGameNotStarted(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $getCurrentPlayer = new GetCurrentPlayer($this->gameId);
        $currentPlayerId = $game->getCurrentPlayerTurn($getCurrentPlayer);

        self::assertNull($currentPlayerId);
    }

    #[Test]
    public function itReturnsCurrentTurn(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->start(...), new StartGame($this->gameId, $this->fixedTime));

        $getCurrentTurn = new GetCurrentTurn($this->gameId);
        $currentTurnId = $game->getCurrentTurn($getCurrentTurn);

        self::assertInstanceOf(Uuid::class, $currentTurnId);
        self::assertEquals($game->getCurrentTurnId(), $currentTurnId);
    }

    #[Test]
    public function itReturnsGameInstance(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $getGame = new GetGame($this->gameId);
        $gameInstance = $game->get($getGame);

        self::assertSame($game, $gameInstance);
    }

    #[Test]
    public function itReturnsActivePlayers(): void
    {
        $createGame = new CreateGame(
            gameId: $this->gameId,
            at: $this->fixedTime,
            deckSize: 88,
        );
        [$game, ] = handle(Game::create(...), $createGame);

        $tester = MessageBusTester::create();
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player1Id));
        $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $this->player2Id));

        $getActivePlayers = new GetActivePlayers($this->gameId);
        $players = $game->getPlayer($getActivePlayers);

        self::assertEquals([$this->player1Id, $this->player2Id], $players);
    }

    #[Test]
    public function itTestsGameStatusEnum(): void
    {
        // Test preparing states
        self::assertTrue(GameStatus::LOBBY->isPreparing());
        self::assertFalse(GameStatus::STARTED->isPreparing());
        self::assertFalse(GameStatus::TURN_IN_PROGRESS->isPreparing());
        self::assertFalse(GameStatus::FINISHED->isPreparing());

        // Test in-progress states
        self::assertFalse(GameStatus::LOBBY->isInProgress());
        self::assertTrue(GameStatus::STARTED->isInProgress());
        self::assertTrue(GameStatus::TURN_IN_PROGRESS->isInProgress());
        self::assertFalse(GameStatus::FINISHED->isInProgress());

        // Test finished state
        self::assertFalse(GameStatus::LOBBY->isFinished());
        self::assertFalse(GameStatus::STARTED->isFinished());
        self::assertFalse(GameStatus::TURN_IN_PROGRESS->isFinished());
        self::assertTrue(GameStatus::FINISHED->isFinished());
    }
}