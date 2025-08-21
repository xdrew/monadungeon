<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\GameLifecycle\GetGame;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Telephantast\MessageBus\MessageBus;

/**
 * Manages AI players in games
 * Handles AI player creation, turn execution, and strategy management
 */
final class AIPlayerManager
{
    private array $activeAIPlayers = [];
    private array $playerStrategies = [];
    private string $defaultStrategy = 'balanced';

    public function __construct(
        private readonly EnhancedAIPlayer $enhancedAIPlayer,
        private readonly VirtualPlayerApiClient $apiClient,
        private readonly MessageBus $messageBus,
        private readonly LoggerInterface $logger,
    ) {}
    
    /**
     * Set the default strategy for new AI players
     */
    public function setDefaultStrategy(string $strategy): void
    {
        $this->defaultStrategy = $strategy;
        $this->logger->info('AI default strategy set', ['strategy' => $strategy]);
    }

    /**
     * Register an AI player for a game
     */
    public function registerAIPlayer(
        Uuid $gameId,
        Uuid $playerId,
        ?string $strategyType = null
    ): void {
        // Use provided strategy or fall back to default
        $strategyType = $strategyType ?? $this->defaultStrategy;
        $key = $this->getPlayerKey($gameId, $playerId);
        
        $this->activeAIPlayers[$key] = [
            'gameId' => $gameId,
            'playerId' => $playerId,
            'strategyType' => $strategyType,
            'active' => true,
            'turnCount' => 0,
            'lastAction' => null,
        ];

        // Configure strategy based on type
        $this->configureStrategy($playerId, $strategyType);

        $this->logger->info("AI player registered", [
            'game_id' => $gameId->toString(),
            'player_id' => $playerId->toString(),
            'strategy' => $strategyType,
        ]);
    }

    /**
     * Execute AI turn if it's the AI player's turn
     */
    public function executeAITurnIfNeeded(Uuid $gameId): bool
    {
        try {
            // Get current turn
            $currentTurn = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
            if (!$currentTurn) {
                return false;
            }

            $currentPlayerId = $currentTurn->playerId;
            $key = $this->getPlayerKey($gameId, $currentPlayerId);

            // Check if current player is an AI
            if (!isset($this->activeAIPlayers[$key]) || !$this->activeAIPlayers[$key]['active']) {
                return false;
            }

            $this->logger->info("Executing AI turn", [
                'game_id' => $gameId->toString(),
                'player_id' => $currentPlayerId->toString(),
                'turn_id' => $currentTurn->turnId->toString(),
            ]);

            // Execute AI turn
            $success = $this->enhancedAIPlayer->executeTurn($gameId, $currentPlayerId);

            if ($success) {
                $this->activeAIPlayers[$key]['turnCount']++;
                $this->activeAIPlayers[$key]['lastAction'] = new \DateTimeImmutable();
            }

            return $success;

        } catch (\Throwable $e) {
            $this->logger->error("Failed to execute AI turn", [
                'game_id' => $gameId->toString(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Execute all AI turns in a game loop
     */
    public function runGameWithAI(Uuid $gameId, int $maxTurns = 100): array
    {
        $results = [
            'turns_executed' => 0,
            'ai_turns' => 0,
            'errors' => [],
            'game_ended' => false,
        ];

        for ($i = 0; $i < $maxTurns; $i++) {
            // Check if game has ended
            $game = $this->messageBus->dispatch(new GetGame($gameId));
            if ($game->status === 'ended' || $game->status === 'finished') {
                $results['game_ended'] = true;
                break;
            }

            // Try to execute AI turn
            if ($this->executeAITurnIfNeeded($gameId)) {
                $results['ai_turns']++;
                $results['turns_executed']++;
                
                // Small delay between turns
                usleep(500000); // 0.5 second
            } else {
                // Not an AI turn or error, wait a bit
                usleep(100000); // 0.1 second
            }
        }

        return $results;
    }

    /**
     * Configure AI strategy based on type
     */
    private function configureStrategy(Uuid $playerId, string $strategyType): void
    {
        // Use centralized configuration
        $config = AIConfiguration::getStrategy($strategyType);
        
        // Remove description field as it's not needed at runtime
        unset($config['description']);

        $this->enhancedAIPlayer->setStrategyConfig($config);
        $this->playerStrategies[$playerId->toString()] = $config;
        
        $this->logger->debug('AI strategy configured', [
            'playerId' => $playerId->toString(),
            'strategy' => $strategyType,
            'config' => $config
        ]);
    }

    /**
     * Update AI player strategy during game
     */
    public function updateStrategy(Uuid $gameId, Uuid $playerId, string $newStrategyType): void
    {
        $key = $this->getPlayerKey($gameId, $playerId);
        
        if (isset($this->activeAIPlayers[$key])) {
            $this->activeAIPlayers[$key]['strategyType'] = $newStrategyType;
            $this->configureStrategy($playerId, $newStrategyType);
            
            $this->logger->info("AI strategy updated", [
                'game_id' => $gameId->toString(),
                'player_id' => $playerId->toString(),
                'new_strategy' => $newStrategyType,
            ]);
        }
    }

    /**
     * Deactivate AI player
     */
    public function deactivateAIPlayer(Uuid $gameId, Uuid $playerId): void
    {
        $key = $this->getPlayerKey($gameId, $playerId);
        
        if (isset($this->activeAIPlayers[$key])) {
            $this->activeAIPlayers[$key]['active'] = false;
            
            $this->logger->info("AI player deactivated", [
                'game_id' => $gameId->toString(),
                'player_id' => $playerId->toString(),
            ]);
        }
    }

    /**
     * Get AI player statistics
     */
    public function getAIPlayerStats(Uuid $gameId, Uuid $playerId): array
    {
        $key = $this->getPlayerKey($gameId, $playerId);
        
        if (!isset($this->activeAIPlayers[$key])) {
            return [];
        }

        return $this->activeAIPlayers[$key];
    }

    /**
     * Get all active AI players for a game
     */
    public function getActiveAIPlayers(Uuid $gameId): array
    {
        $players = [];
        
        foreach ($this->activeAIPlayers as $key => $player) {
            if ($player['gameId']->equals($gameId) && $player['active']) {
                $players[] = $player;
            }
        }

        return $players;
    }

    /**
     * Clear all AI players for a game
     */
    public function clearGameAIPlayers(Uuid $gameId): void
    {
        foreach ($this->activeAIPlayers as $key => $player) {
            if ($player['gameId']->equals($gameId)) {
                unset($this->activeAIPlayers[$key]);
            }
        }

        $this->logger->info("All AI players cleared for game", [
            'game_id' => $gameId->toString(),
        ]);
    }

    /**
     * Get player key for storage
     */
    private function getPlayerKey(Uuid $gameId, Uuid $playerId): string
    {
        return $gameId->toString() . ':' . $playerId->toString();
    }
}