<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Game\GameLifecycle\Error\CannotAddPlayerToAlreadyFullGame;
use App\Game\GameLifecycle\Error\CannotAddPlayerToAlreadyPreparedGame;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Player\GetActivePlayers;
use App\Game\Player\GetPlayer;
use App\Game\Player\ItemAddedToInventory;
use App\Game\Player\Player;
use App\Game\Player\ResetPlayerHP;
use App\Game\Testing\TestMode;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnEnded;
use App\Infrastructure\Doctrine\AggregateRoot;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidArrayJsonType;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Telephantast\MessageBus\EntityHandler\FindBy;
use Telephantast\MessageBus\EntityHandler\Property;
use Telephantast\MessageBus\Handler\Mapping\Handler;
use Telephantast\MessageBus\MessageContext;

#[Entity]
#[Table(schema: 'game')]
#[FindBy(['gameId' => new Property('gameId')])]
class Game extends AggregateRoot
{
    private const int MAX_PLAYERS_COUNT = 4;

    #[Column(type: Types::STRING, enumType: GameStatus::class)]
    private GameStatus $status = GameStatus::LOBBY;

    /**
     * @var list<Uuid>
     */
    #[Column(type: UuidArrayJsonType::class)]
    private array $players = [];

    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $currentPlayerId = null;

    #[Column(type: Types::INTEGER)]
    private int $currentTurnNumber = 0;

    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $currentTurnId = null;

    #[Column(type: JsonType::class, columnDefinition: 'jsonb')]
    private array $leaderboard = [];

    #[Column(type: UuidType::class, nullable: true)]
    private ?Uuid $winnerId = null;

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $gameId,
    ) {}

    #[Handler]
    public static function create(CreateGame $command, MessageContext $messageContext): self
    {
        $game = new self(
            gameId: $command->gameId,
        );
        $messageContext->dispatch(new GameCreated(
            gameId: $command->gameId,
            gameCreateTime: $command->at,
            deckSize: $command->deckSize,
        ));

        return $game;
    }

    #[Handler]
    public function getPlayer(GetActivePlayers $message): array
    {
        return $this->getPlayers();
    }

    /**
     * @throws CannotAddPlayerToAlreadyFullGame
     * @throws CannotAddPlayerToAlreadyPreparedGame
     */
    #[Handler]
    public function addPlayer(AddPlayer $command, MessageContext $messageContext): void
    {
        $players = array_values(array_unique(array_merge($this->players, [$command->playerId])));
        if (\count($players) > self::MAX_PLAYERS_COUNT) {
            throw new CannotAddPlayerToAlreadyFullGame();
        }

        if (!$this->status->isPreparing()) {
            throw new CannotAddPlayerToAlreadyPreparedGame();
        }
        $this->players = $players;

        // Check TestMode for player HP configuration
        $hp = Player::MAX_HP;
        $testMode = TestMode::getInstance();
        if ($testMode->isEnabled()) {
            $testHp = $testMode->getPlayerHp($this->gameId->toString(), $command->playerId->toString());
            if ($testHp !== null) {
                $hp = $testHp;
            }
        }

        $messageContext->dispatch(new PlayerAdded(
            gameId: $this->gameId,
            playerId: $command->playerId,
            hp: $hp,
            externalId: $command->externalId,
            username: $command->username,
            walletAddress: $command->walletAddress,
            isAi: $command->isAi,
        ));
    }

    #[Handler]
    public function start(StartGame $command, MessageContext $messageContext): void
    {
        if (!$this->status->isPreparing()) {
            return;
        }

        if ($this->players === []) {
            return;
        }

        $this->status = GameStatus::STARTED;
        $this->currentPlayerId = $this->players[0];
        $this->currentTurnNumber = 1;

        $messageContext->dispatch(new GameStarted(
            gameId: $this->gameId,
            gameStartTime: $command->at,
        ));

        $messageContext->dispatch(new TurnChanged(
            gameId: $this->gameId,
            currentPlayerId: $this->currentPlayerId,
            turnNumber: $this->currentTurnNumber,
            changedAt: $command->at,
        ));

        // Start the first turn
        $turnId = Uuid::v7();
        $this->currentTurnId = $turnId;
        $messageContext->dispatch(new StartTurn(
            gameId: $this->gameId,
            playerId: $this->currentPlayerId,
            turnNumber: $this->currentTurnNumber,
            turnId: $turnId,
            at: $command->at,
        ));
    }

    #[Handler]
    public function turnEnded(TurnEnded $event, MessageContext $messageContext): void
    {
        $messageContext->dispatch(new NextTurn($event->gameId));
    }

    #[Handler]
    public function nextTurn(NextTurn $command, MessageContext $messageContext): void
    {
        if (!$this->status->isInProgress()) {
            return;
        }

        if ($this->players === []) {
            return;
        }

        // Find the current player's index
        $currentPlayerIndex = -1;
        foreach ($this->players as $index => $playerId) {
            if ($this->currentPlayerId instanceof Uuid && $playerId->equals($this->currentPlayerId)) {
                $currentPlayerIndex = $index;
                break;
            }
        }

        // Determine the next player
        $nextPlayerIndex = ($currentPlayerIndex + 1) % \count($this->players);
        $this->currentPlayerId = $this->players[$nextPlayerIndex];
        ++$this->currentTurnNumber;
        $this->status = GameStatus::TURN_IN_PROGRESS;

        $messageContext->dispatch(new TurnChanged(
            gameId: $this->gameId,
            currentPlayerId: $this->currentPlayerId,
            turnNumber: $this->currentTurnNumber,
            changedAt: $command->at,
        ));

        // Check if the player was stunned (HP = 0) and regenerate HP if needed
        $player = $messageContext->dispatch(new GetPlayer($this->currentPlayerId));
        $isPlayerDefeated = $player->isDefeated();

        if ($isPlayerDefeated) {
            // Player was stunned, regenerate HP
            $messageContext->dispatch(new ResetPlayerHP(
                playerId: $this->currentPlayerId,
                gameId: $this->gameId,
                afterStun: true,
            ));

            // Start the new turn
            $turnId = Uuid::v7();
            $this->currentTurnId = $turnId;
            $messageContext->dispatch(new StartTurn(
                gameId: $this->gameId,
                playerId: $this->currentPlayerId,
                turnNumber: $this->currentTurnNumber,
                turnId: $turnId,
                at: $command->at,
            ));

            // Immediately end the turn since stunned players skip their next turn
            $messageContext->dispatch(new EndTurn(
                turnId: $turnId,
                gameId: $this->gameId,
                playerId: $this->currentPlayerId,
                at: $command->at,
            ));
        } else {
            // Start the new turn normally
            $turnId = Uuid::v7();
            $this->currentTurnId = $turnId;
            $messageContext->dispatch(new StartTurn(
                gameId: $this->gameId,
                playerId: $this->currentPlayerId,
                turnNumber: $this->currentTurnNumber,
                turnId: $turnId,
                at: $command->at,
            ));
        }
    }

    #[Handler]
    public function getCurrentPlayerTurn(GetCurrentPlayer $query): ?Uuid
    {
        return $this->currentPlayerId;
    }

    /**
     * @return Uuid[]
     */
    public function getPlayers(): array
    {
        return $this->players;
    }

    public function getCurrentTurnNumber(): int
    {
        return $this->currentTurnNumber;
    }

    public function getCurrentPlayerId(): ?Uuid
    {
        return $this->currentPlayerId;
    }

    public function getStatus(): GameStatus
    {
        return $this->status;
    }

    public function getCurrentTurnId(): ?Uuid
    {
        return $this->currentTurnId;
    }

    public function getGameId(): Uuid
    {
        return $this->gameId;
    }

    #[Handler]
    public function getCurrentTurn(GetCurrentTurn $query): ?Uuid
    {
        return $this->currentTurnId;
    }

    #[Handler]
    public function get(GetGame $query): self
    {
        return $this;
    }

    #[Handler]
    public function onItemPicked(ItemAddedToInventory $event, MessageContext $messageContext): void
    {
        if (!$event->item->type->endsGame()) {
            return;
        }
        $messageContext->dispatch(new EndGame($event->gameId));
    }

    #[Handler]
    public function endGame(EndGame $event, MessageContext $messageContext): void
    {
        $this->status = GameStatus::FINISHED;

        // Get player UUIDs from this game instance
        $playerIds = $this->getPlayers();

        $scores = [];
        foreach ($playerIds as $playerId) {
            $treasureSum = 0;
            // Get the actual Player object for this ID
            $player = $messageContext->dispatch(new GetPlayer($playerId));
            $inventory = $player->getInventory();
            if (isset($inventory[ItemCategory::TREASURE->value])) {
                foreach ($inventory[ItemCategory::TREASURE->value] as $treasure) {
                    $treasure = Item::fromAnything($treasure);
                    $treasureSum += $treasure->treasureValue;
                }
            }
            $scores[$playerId->toString()] = $treasureSum;
        }
        arsort($scores);
        $this->leaderboard = $scores;

        if (\count($scores) > 0) {
            $this->winnerId = Uuid::fromString(array_key_first($scores));
        }
        $messageContext->dispatch(new GameEnded(
            gameId: $event->gameId,
            winnerId: $this->winnerId,
            scores: $scores,
        ));
    }
}
