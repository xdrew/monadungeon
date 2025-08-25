<?php

declare(strict_types=1);

namespace App\Game\Testing;

use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\StartGame;
use App\Game\Player\Player;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\MessageBus\Handler\Mapping\Handler;
use Telephantast\MessageBus\MessageBus;

final readonly class SetupTestGameHandler
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Handler]
    public function __invoke(SetupTestGame $command): void
    {
        // Only allow in test/dev environment
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        if ($appEnv !== 'test' && $appEnv !== 'dev') {
            return;
        }

        $testMode = TestMode::getInstance();
        $testMode->enable();

        $config = $command->configuration;
        $gameId = $command->gameId;
        $gameIdString = $gameId->toString();

        // Set up test mode configurations BEFORE creating the game
        // so they are used during game creation

        // Set up fixed deck if tiles specified
        // This will be used by Deck::createClassic when handling GameCreated event
        if (\count($config->tileSequence) > 0) {
            $testMode->setFixedDeck($gameIdString, $config->tileSequence);
        }

        // Set up fixed bag if items specified
        // This will be used by Bag::createClassic when handling DeckCreated event
        if (\count($config->itemSequence) > 0) {
            $testMode->setFixedBag($gameIdString, $config->itemSequence);
        }

        // Set up dice rolls in TestMode
        // This will be used by Field::getNextDiceRoll when rolling dice
        if (\count($config->diceRolls) > 0) {
            $testMode->setDiceRolls($config->diceRolls);
        }

        // Set up player HP configurations before adding players
        if (\count($config->playerConfigs) > 0) {
            foreach ($config->playerConfigs as $playerId => $playerConfig) {
                // If maxHp is specified, use it as the initial HP
                // Otherwise use the default MAX_HP
                $hp = $playerConfig->maxHp ?? Player::MAX_HP;
                $testMode->setPlayerHp($gameIdString, $playerId, $hp);
            }
        }

        // Now create the game with test mode configurations active
        // This will trigger:
        // - Deck creation which uses TestMode for tile sequence
        // - Bag creation which uses TestMode for item sequence
        // - Field creation (dice rolls will be used via TestMode when needed)
        $this->messageBus->dispatch(new CreateGame(
            gameId: $gameId,
            at: new \DateTimeImmutable(),
        ));

        // Add players if specified in configuration
        // The HP will be set from TestMode during player creation
        if (\count($config->playerConfigs) > 0) {
            foreach (array_keys($config->playerConfigs) as $playerId) {
                $this->messageBus->dispatch(new AddPlayer(
                    gameId: $gameId,
                    playerId: Uuid::fromString($playerId),
                    isAi: false, // Explicitly set as human player for tests
                ));
            }
        }

        // Start the game
        $this->messageBus->dispatch(new StartGame(
            gameId: $gameId,
            at: new \DateTimeImmutable(),
        ));

        // Apply player max actions after game is started
        // Max actions still need to be applied separately
        if (\count($config->playerConfigs) > 0) {
            foreach ($config->playerConfigs as $playerId => $playerConfig) {
                if ($playerConfig->maxActions !== null) {
                    $this->messageBus->dispatch(new SetPlayerTestState(
                        gameId: $gameId,
                        playerId: Uuid::fromString($playerId),
                        config: new PlayerTestConfig(
                            maxHp: null, // Don't change maxHp after creation
                            maxActions: $playerConfig->maxActions,
                        ),
                    ));
                }
            }
        }
    }
}
