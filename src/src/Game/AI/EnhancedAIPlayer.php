<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\Battle\BattleResult;
use App\Game\Battle\GetBattle;
use App\Game\Battle\FinalizeBattle;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\Field\PickTile;
use App\Game\Field\PlaceTile;
use App\Game\Field\Tile;
use App\Game\Field\TileSide;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\Game;
use App\Game\Item\Item;
use App\Game\Item\ItemType;
use App\Game\Movement\Commands\MovePlayer;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\Player;
use App\Game\Player\PickItem;
use App\Game\Player\ReplaceInventoryItem;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Telephantast\MessageBus\MessageBus;

/**
 * Enhanced AI Player with complete game mechanics support
 * Based on analysis of 19 game scenarios
 */
final class EnhancedAIPlayer implements VirtualPlayerStrategy
{
    // Strategy configuration
    private array $strategyConfig = [
        'aggressive' => true,
        'preferTreasures' => true,
        'riskTolerance' => 0.7,
        'healingThreshold' => 1,
        'inventoryPriority' => ['sword', 'axe', 'dagger', 'fireball', 'key'],
    ];

    // State tracking
    private ?Game $currentGame = null;
    private ?Player $currentPlayer = null;
    private ?Field $currentField = null;
    private array $lastBattleInfo = [];
    private array $actionLog = [];
    
    // Turn management
    private const MAX_ACTIONS_PER_TURN = 4;
    private int $currentTurnActions = 0;
    private array $turnActionHistory = [];

    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly LoggerInterface $logger,
        private readonly VirtualPlayerApiClient $apiClient,
    ) {}
    
    /**
     * Get the action log for the current turn
     */
    public function getActionLog(): array
    {
        return $this->actionLog;
    }

    /**
     * Execute a complete AI turn with up to 4 actions
     */
    public function executeTurn(Uuid $gameId, Uuid $playerId): bool
    {
        try {
            $this->logger->info("AI executing turn", [
                'game_id' => $gameId->toString(),
                'player_id' => $playerId->toString(),
            ]);

            // Reset turn tracking and action log
            $this->currentTurnActions = 0;
            $this->turnActionHistory = [];
            $this->actionLog = [];

            // Refresh game state
            $this->refreshGameState($gameId, $playerId);

            // Check if it's our turn
            if (!$this->isMyTurn($playerId)) {
                $this->logger->info("Not AI player's turn");
                $this->actionLog[] = [
                    'type' => 'ai_check_failed',
                    'timestamp' => date('H:i:s.v'),
                    'details' => ['reason' => 'Not AI player turn']
                ];
                return false;
            }

            // Check if stunned
            if ($this->isStunned()) {
                $this->logger->info("AI player is stunned, skipping turn");
                $this->actionLog[] = [
                    'type' => 'ai_stunned',
                    'timestamp' => date('H:i:s.v'),
                    'details' => ['reason' => 'Player is stunned']
                ];
                return true;
            }

            // Get current turn ID
            $currentTurn = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
            if (!$currentTurn) {
                $this->logger->error("Could not get current turn");
                $this->actionLog[] = [
                    'type' => 'ai_check_failed',
                    'timestamp' => date('H:i:s.v'),
                    'details' => ['reason' => 'Could not get current turn']
                ];
                return false;
            }

            // Execute turn strategy with multiple actions
            return $this->executeTurnStrategyWithActions($gameId, $playerId, $currentTurn->turnId);

        } catch (\Throwable $e) {
            $this->logger->error("AI turn execution failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->actionLog[] = [
                'type' => 'ai_error',
                'timestamp' => date('H:i:s.v'),
                'details' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ];
            return false;
        }
    }

    /**
     * Execute turn strategy with support for multiple actions (up to 4)
     */
    private function executeTurnStrategyWithActions(Uuid $gameId, Uuid $playerId, Uuid $turnId): bool
    {
        $this->logger->info("Starting AI turn with up to " . self::MAX_ACTIONS_PER_TURN . " actions");
        
        // Log AI turn start
        $this->actionLog[] = [
            'type' => 'ai_start',
            'timestamp' => date('H:i:s.v'),
            'details' => ['message' => 'Starting AI turn - 1 tile placement']
        ];
        
        // Keep executing actions until we hit the limit or decide to end
        while ($this->currentTurnActions < self::MAX_ACTIONS_PER_TURN) {
            $this->logger->debug("AI executing action " . ($this->currentTurnActions + 1) . " of " . self::MAX_ACTIONS_PER_TURN);
            
            // Refresh state for each action
            $this->refreshGameState($gameId, $playerId);
            
            // Decide next action based on current state
            $actionResult = $this->decideAndExecuteNextAction($gameId, $playerId, $turnId);
            
            if ($actionResult === null) {
                // No more beneficial actions, end turn
                $this->logger->info("No more beneficial actions, ending turn after {$this->currentTurnActions} actions");
                break;
            }
            
            // Increment action count BEFORE checking for end conditions
            // This ensures tile placement counts as an action
            $this->currentTurnActions++;
            $this->turnActionHistory[] = $actionResult;
            
            if ($actionResult['endsNow'] ?? false) {
                // Action requires turn end (e.g., healing)
                $this->logger->info("Action requires turn end, ending after {$this->currentTurnActions} actions");
                break;
            }
            
            // Small delay between actions for game processing
            usleep(100000); // 0.1 second
        }
        
        // End turn after all actions or when forced
        return $this->endTurn($gameId, $playerId, $turnId);
    }

    /**
     * Decide and execute the next best action
     */
    private function decideAndExecuteNextAction(Uuid $gameId, Uuid $playerId, Uuid $turnId): ?array
    {
        // Clear battle info from previous action
        $this->lastBattleInfo = [];
        
        // Priority 1: If critically low HP and healing available
        if ($this->needsHealing()) {
            $this->logger->info("AI needs healing", [
                'current_hp' => $this->currentPlayer->hp,
                'max_hp' => $this->currentPlayer->maxHp,
                'threshold' => $this->strategyConfig['healingThreshold']
            ]);
            
            // Log healing fountain availability
            $healingPositions = $this->currentField->healingFountainPositions ?? [];
            $this->logger->info("Healing fountains available", [
                'count' => count($healingPositions),
                'positions' => $healingPositions
            ]);
            
            $result = $this->moveToHealingFountain($gameId, $playerId, $turnId);
            if ($result) {
                $this->actionLog[] = [
                    'type' => 'ai_healing',
                    'timestamp' => date('H:i:s.v'),
                    'details' => ['message' => 'Moving to healing fountain due to low HP']
                ];
                return ['action' => 'heal', 'success' => $result, 'endsNow' => true];
            } else {
                $this->logger->warning("AI needs healing but couldn't move to fountain");
            }
        }
        
        // Priority 2: If this is our first action, always try to place a tile
        if ($this->currentTurnActions === 0) {
            $result = $this->executeTilePlacementStrategy($gameId, $playerId, $turnId);
            if ($result) {
                // Check if battle occurred during tile placement
                $battleOccurred = !empty($this->lastBattleInfo) && ($this->lastBattleInfo['occurred'] ?? false);
                
                // Battle forces turn end
                return ['action' => 'place_tile', 'success' => $result, 'endsNow' => $battleOccurred];
            }
        }
        
        // Priority 2.5: Always check if there's an item at current position (from previous battle)
        $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        
        // Try to pick up any item at current position
        // This handles cases where a battle occurred in the previous action
        $pickResult = $this->apiClient->pickItem(
            $gameId,
            $playerId,
            $turnId,
            $currentPosition->positionX,
            $currentPosition->positionY
        );
        
        if ($pickResult['success']) {
            $this->logger->info("AI picked up item at current position", [
                'position' => $currentPosition->toString()
            ]);
            
            // Check if inventory is full
            if (isset($pickResult['response']['inventoryFull']) && $pickResult['response']['inventoryFull']) {
                $this->handleInventoryFull($gameId, $playerId, $turnId, $pickResult['response']);
            }
            
            // Item pickup doesn't end turn anymore, we can continue
            return ['action' => 'pick_item', 'success' => true, 'endsNow' => false];
        }
        
        // Original check for known items on the map
        if ($this->hasItemAtPosition($currentPosition)) {
            $result = $this->apiClient->pickItem(
                $gameId,
                $playerId,
                $turnId,
                $currentPosition->positionX,
                $currentPosition->positionY
            );
            
            if ($result['success']) {
                $this->logger->info("AI picked up item after battle", [
                    'position' => $currentPosition->toString()
                ]);
                
                // Check if inventory is full
                if (isset($result['response']['inventoryFull']) && $result['response']['inventoryFull']) {
                    $this->handleInventoryFull($gameId, $playerId, $turnId, $result['response']);
                }
                
                // Item pickup doesn't end turn anymore, we can continue
                return ['action' => 'pick_item', 'success' => true, 'endsNow' => false];
            }
        }
        
        // Priority 3: Look for valuable movements (items, teleports)
        $movement = $this->findBeneficialMovement($playerId);
        if ($movement) {
            $result = $this->executeMovement($gameId, $playerId, $turnId, $movement);
            if ($result) {
                // Check if battle occurred during this movement
                $battleOccurred = !empty($this->lastBattleInfo) && ($this->lastBattleInfo['occurred'] ?? false);
                
                return [
                    'action' => 'movement',
                    'type' => $movement['type'],
                    'success' => $result,
                    'endsNow' => $battleOccurred || ($movement['endsAfterMove'] ?? false)
                ];
            }
        }
        
        // Priority 4: Explore more tiles if we have actions left
        if ($this->currentTurnActions < self::MAX_ACTIONS_PER_TURN - 1) {
            $availablePlaces = $this->messageBus->dispatch(
                new GetAvailablePlacesForPlayer($gameId, $playerId)
            );
            
            if (!empty($availablePlaces->moveTo)) {
                // Move to explore more of the map
                $explorationMove = $this->chooseBestExplorationMove($availablePlaces->moveTo);
                
                if ($explorationMove) {
                    $result = $this->apiClient->movePlayer(
                        $gameId,
                        $playerId,
                        $turnId,
                        $currentPosition->positionX,
                        $currentPosition->positionY,
                        $explorationMove->positionX,
                        $explorationMove->positionY,
                        false
                    );
                    
                    if ($result['success']) {
                        // Check if battle occurred
                        $battleOccurred = isset($result['response']['battleInfo']);
                        if ($battleOccurred) {
                            $battleInfo = $result['response']['battleInfo'];
                            $this->handleBattle($gameId, $playerId, $turnId, $battleInfo);
                            
                            // If we won and there's a reward, pick it up immediately
                            if ($battleInfo['result'] === 'win' && isset($battleInfo['reward'])) {
                                $position = FieldPlace::fromString($battleInfo['position']);
                                $pickResult = $this->apiClient->pickItem(
                                    $gameId,
                                    $playerId,
                                    $turnId,
                                    $position->positionX,
                                    $position->positionY
                                );
                                
                                if ($pickResult['success']) {
                                    $this->logger->info("AI picked up battle reward after exploration", [
                                        'item' => $battleInfo['reward']['name'] ?? 'unknown'
                                    ]);
                                    
                                    if (isset($pickResult['response']['inventoryFull']) && $pickResult['response']['inventoryFull']) {
                                        $this->handleInventoryFull($gameId, $playerId, $turnId, $pickResult['response']);
                                    }
                                }
                            }
                        }
                        return [
                            'action' => 'exploration_move',
                            'success' => true,
                            'endsNow' => $battleOccurred // Battle forces turn end
                        ];
                    }
                }
            }
        }
        
        // No beneficial action found
        return null;
    }

    /**
     * Execute the main turn strategy (legacy - for single action)
     */
    private function executeTurnStrategy(Uuid $gameId, Uuid $playerId, Uuid $turnId): bool
    {
        // This is kept for backward compatibility but redirects to multi-action
        return $this->executeTurnStrategyWithActions($gameId, $playerId, $turnId);
    }

    /**
     * Execute tile placement strategy
     */
    private function executeTilePlacementStrategy(Uuid $gameId, Uuid $playerId, Uuid $turnId): bool
    {
        // Get available places for tile placement
        $availablePlaces = $this->messageBus->dispatch(
            new GetAvailablePlacesForPlayer($gameId, $playerId)
        );

        if (empty($availablePlaces->placeTile)) {
            // Only movement available
            return false; // Return false to indicate no tile was placed
        }

        // Choose best placement position
        $bestPosition = $this->chooseBestTilePlacement($availablePlaces->placeTile);
        
        // Get current position
        $currentPosition = $this->messageBus->dispatch(
            new GetPlayerPosition($gameId, $playerId)
        );

        // Log tile placement decision
        $this->actionLog[] = [
            'type' => 'ai_decision',
            'timestamp' => date('H:i:s.v'),
            'details' => [
                'decision' => 'Executing tile placement sequence',
                'target' => "{$bestPosition->positionX},{$bestPosition->positionY}"
            ]
        ];
        
        // Execute tile placement sequence using API client
        $result = $this->apiClient->placeTileSequence(
            $gameId,
            $playerId,
            $turnId,
            $bestPosition->positionX,
            $bestPosition->positionY,
            TileSide::THREE, // Default to 3 open sides
            $currentPosition->positionX,
            $currentPosition->positionY
        );

        if (!$result['success']) {
            $this->logger->error("Tile placement sequence failed", $result);
            $this->actionLog[] = [
                'type' => 'error',
                'timestamp' => date('H:i:s.v'),
                'details' => ['message' => 'Tile placement failed', 'error' => $result['error'] ?? 'Unknown']
            ];
            return false;
        }
        
        // Log each step from the placement sequence
        if (isset($result['actions'])) {
            foreach ($result['actions'] as $action) {
                if (isset($action['step']) && isset($action['result'])) {
                    $this->actionLog[] = [
                        'type' => $action['step'],
                        'timestamp' => date('H:i:s.v'),
                        'details' => ['result' => $action['result']]
                    ];
                }
            }
        }

        $this->logger->debug("PlaceTileSequence result", [
            'has_actions' => isset($result['actions']),
            'action_count' => isset($result['actions']) ? count($result['actions']) : 0,
            'result_keys' => array_keys($result)
        ]);

        // Check if battle occurred and handle it
        $battleOccurred = false;
        $battleWon = false;
        $hasReward = false;
        
        if (isset($result['actions'])) {
            $this->logger->debug("Checking actions from placeTileSequence", [
                'action_count' => count($result['actions'])
            ]);
            
            foreach ($result['actions'] as $action) {
                $this->logger->debug("Processing action", [
                    'step' => $action['step'],
                    'has_battleInfo' => isset($action['result']['response']['battleInfo'])
                ]);
                
                if ($action['step'] === 'move_player' && isset($action['result']['response']['battleInfo'])) {
                    $battleOccurred = true;
                    $battleInfo = $action['result']['response']['battleInfo'];
                    
                    // Log battle detection
                    $this->actionLog[] = [
                        'type' => 'battle_detected',
                        'timestamp' => date('H:i:s.v'),
                        'details' => ['battleResult' => $battleInfo['result'] ?? 'unknown']
                    ];
                    
                    // Handle the battle
                    $this->handleBattle($gameId, $playerId, $turnId, $battleInfo);
                    
                    // Check if we won and there's a reward
                    if ($battleInfo['result'] === 'win' && isset($battleInfo['reward'])) {
                        $battleWon = true;
                        $hasReward = true;
                        
                        // Check if pickup was already handled by VirtualPlayerApiClient
                        $pickupAlreadyDone = $battleInfo['pickupSuccess'] ?? false;
                        
                        if ($pickupAlreadyDone) {
                            $this->logger->info("Battle reward already picked up by API client", [
                                'monster' => $battleInfo['monsterType'] ?? 'unknown',
                                'reward' => $battleInfo['reward']['name'] ?? 'unknown'
                            ]);
                        } else {
                            $this->logger->info("Battle won with reward, attempting manual pickup", [
                                'monster' => $battleInfo['monsterType'] ?? 'unknown',
                                'reward' => $battleInfo['reward']['name'] ?? 'unknown',
                                'position' => $battleInfo['position']
                            ]);
                            
                            // Try to pick up the reward manually as fallback
                            $position = FieldPlace::fromString($battleInfo['position']);
                            
                            // Add explicit action tracking
                            $this->actionLog[] = [
                                'type' => 'pick_item_attempt',
                                'position' => $battleInfo['position'],
                                'item' => $battleInfo['reward']['name'] ?? 'unknown'
                            ];
                            
                            $pickResult = $this->apiClient->pickItem(
                                $gameId,
                                $playerId,
                                $turnId,
                                $position->positionX,
                                $position->positionY
                            );
                            
                            if ($pickResult['success']) {
                                $this->logger->info("AI successfully picked up battle reward", [
                                    'item' => $battleInfo['reward']['name'] ?? 'unknown'
                                ]);
                                
                                // Handle inventory full
                                if (isset($pickResult['response']['inventoryFull']) && $pickResult['response']['inventoryFull']) {
                                    $this->handleInventoryFull($gameId, $playerId, $turnId, $pickResult['response']);
                                }
                            } else {
                                $this->logger->error("Failed to pick up battle reward", [
                                    'item' => $battleInfo['reward']['name'] ?? 'unknown',
                                    'position' => $battleInfo['position'],
                                    'error' => $pickResult['response']['message'] ?? 'Unknown error'
                                ]);
                            }
                        }
                    }
                }
                
                // Also check handle_battle step for additional pickup info
                if ($action['step'] === 'handle_battle' && isset($action['result'])) {
                    $battleHandleResult = $action['result'];
                    if ($battleHandleResult['battleOccurred'] ?? false) {
                        $battleOccurred = true;
                        
                        if (($battleHandleResult['pickupAttempted'] ?? false) && !($battleHandleResult['pickupSuccess'] ?? false)) {
                            $this->logger->warning("Battle reward pickup failed in API client", [
                                'error' => $battleHandleResult['pickupError'] ?? 'Unknown'
                            ]);
                        }
                    }
                }
            }
        }

        // Store battle status for the caller to check
        $this->lastBattleInfo = $battleOccurred ? ['occurred' => true] : [];
        
        // Return true to indicate tile was placed successfully
        return true;
    }

    /**
     * Handle battle logic
     */
    private function handleBattle(Uuid $gameId, Uuid $playerId, Uuid $turnId, array $battleInfo): bool
    {
        $battleId = Uuid::fromString($battleInfo['battleId']);
        $result = $battleInfo['result'];

        $this->logger->info("AI handling battle", [
            'result' => $result,
            'monster' => $battleInfo['monsterType'] ?? 'unknown',
            'damage' => $battleInfo['totalDamage'] ?? 0,
        ]);

        switch ($result) {
            case 'win':
                return $this->handleBattleVictory($gameId, $playerId, $turnId, $battleInfo);
            case 'draw':
                return $this->handleBattleDraw($gameId, $playerId, $turnId, $battleId, $battleInfo);
            case 'loose':
                return $this->handleBattleDefeat($gameId, $playerId, $turnId, $battleId, $battleInfo);
            default:
                $this->logger->error("Unknown battle result", ['result' => $result]);
                return false;
        }
    }

    /**
     * Handle battle victory
     */
    private function handleBattleVictory(Uuid $gameId, Uuid $playerId, Uuid $turnId, array $battleInfo): bool
    {
        $this->logger->info("Battle won!", [
            'monster' => $battleInfo['monsterType'] ?? 'unknown',
            'has_reward' => isset($battleInfo['reward'])
        ]);
        
        // Note: Item pickup is handled separately in the next action
        // This allows the AI to continue with more actions after battle
        return true;
    }

    /**
     * Handle battle draw (can use consumables)
     */
    private function handleBattleDraw(Uuid $gameId, Uuid $playerId, Uuid $turnId, Uuid $battleId, array $battleInfo): bool
    {
        $consumables = $battleInfo['availableConsumables'] ?? [];
        
        // Choose consumables to use
        $selectedConsumables = $this->chooseConsumables($consumables, $battleInfo);
        
        // Finalize battle with consumables
        $result = $this->apiClient->finalizeBattle(
            $gameId,
            $playerId,
            $turnId,
            $battleId,
            $selectedConsumables,
            true // Try to pick up item
        );

        return $result['success'];
    }

    /**
     * Handle battle defeat
     */
    private function handleBattleDefeat(Uuid $gameId, Uuid $playerId, Uuid $turnId, Uuid $battleId, array $battleInfo): bool
    {
        $consumables = $battleInfo['availableConsumables'] ?? [];
        
        if (!empty($consumables)) {
            $selectedConsumables = $this->chooseConsumables($consumables, $battleInfo);
            
            if (!empty($selectedConsumables)) {
                $result = $this->apiClient->finalizeBattle(
                    $gameId,
                    $playerId,
                    $turnId,
                    $battleId,
                    $selectedConsumables,
                    true
                );
                return $result['success'];
            }
        }

        // Accept defeat
        $result = $this->apiClient->finalizeBattle(
            $gameId,
            $playerId,
            $turnId,
            $battleId,
            [],
            false
        );

        return $result['success'];
    }

    /**
     * Handle inventory full situation
     */
    private function handleInventoryFull(Uuid $gameId, Uuid $playerId, Uuid $turnId, array $pickupResult): bool
    {
        $newItem = $pickupResult['item'];
        $currentInventory = $pickupResult['currentInventory'];
        
        // Choose item to replace
        $itemToReplace = $this->chooseItemToReplace($newItem, $currentInventory);
        
        if ($itemToReplace) {
            $result = $this->apiClient->inventoryAction(
                $gameId,
                $playerId,
                $turnId,
                'replace',
                Uuid::fromString($newItem['itemId']),
                Uuid::fromString($itemToReplace['itemId'])
            );
            return $result['success'];
        }

        // Leave the item
        return true;
    }

    // ==================== VirtualPlayerStrategy Interface Implementation ====================

    /**
     * Choose which tile to pick from available tiles
     */
    public function chooseTile(array $availableTiles, Field $field, Uuid $playerId): Tile
    {
        // Simple strategy: pick the first available tile
        // More sophisticated strategies could analyze tile features
        return $availableTiles[0];
    }

    /**
     * Choose where to place the selected tile
     */
    public function chooseTilePlacement(Tile $tile, array $availablePlaces, Field $field, Uuid $playerId): FieldPlace
    {
        // Evaluate each position
        $bestPosition = null;
        $bestScore = -999;

        foreach ($availablePlaces as $place) {
            $score = $this->evaluatePosition($place, $field, $playerId);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPosition = $place;
            }
        }

        return $bestPosition ?? $availablePlaces[0];
    }

    /**
     * Choose the optimal orientation for the tile
     */
    public function chooseTileOrientation(Tile $tile, FieldPlace $position, Field $field): \App\Game\Field\TileOrientation
    {
        // For now, return the default orientation
        // More sophisticated strategies could analyze neighboring tiles
        return $tile->orientation;
    }

    /**
     * Choose which position to move to
     */
    public function chooseMovement(FieldPlace $currentPosition, array $availableMoves, Field $field, Player $player): FieldPlace
    {
        // Evaluate each move
        $bestMove = null;
        $bestScore = -999;

        foreach ($availableMoves as $move) {
            $score = $this->evaluateMovePosition($move, $field, $player);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMove = $move;
            }
        }

        return $bestMove ?? $availableMoves[0];
    }

    // ==================== Helper Methods ====================

    /**
     * Refresh game state
     */
    private function refreshGameState(Uuid $gameId, Uuid $playerId): void
    {
        $this->currentGame = $this->messageBus->dispatch(new GetGame($gameId));
        $this->currentPlayer = $this->messageBus->dispatch(new GetPlayer($gameId, $playerId));
        $this->currentField = $this->messageBus->dispatch(new GetField($gameId));
    }

    /**
     * Check if it's the AI player's turn
     */
    private function isMyTurn(Uuid $playerId): bool
    {
        if (!$this->currentGame) {
            return false;
        }

        $currentTurn = $this->messageBus->dispatch(new GetCurrentTurn($this->currentGame->gameId));
        return $currentTurn && $currentTurn->playerId->equals($playerId);
    }

    /**
     * Check if player is stunned
     */
    private function isStunned(): bool
    {
        return $this->currentPlayer && $this->currentPlayer->hp <= 0;
    }

    /**
     * Check if player needs healing
     */
    private function needsHealing(): bool
    {
        if (!$this->currentPlayer) {
            return false;
        }

        // Priority healing when at 1 HP (critically low)
        // This matches the healing threshold in the strategy config
        return $this->currentPlayer->hp <= $this->strategyConfig['healingThreshold'] && 
               $this->currentPlayer->hp < $this->currentPlayer->maxHp;
    }

    /**
     * Move to healing fountain if available
     */
    private function moveToHealingFountain(Uuid $gameId, Uuid $playerId, Uuid $turnId): bool
    {
        if (!$this->currentField) {
            return false;
        }

        // Find healing fountain positions
        $healingPositions = $this->currentField->healingFountainPositions ?? [];
        
        if (empty($healingPositions)) {
            return false;
        }

        // Get current position
        $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        
        // Get available moves
        $availablePlaces = $this->messageBus->dispatch(
            new GetAvailablePlacesForPlayer($gameId, $playerId)
        );
        
        if (empty($availablePlaces->moveTo)) {
            return false;
        }
        
        // First, check if any healing fountain is directly reachable
        foreach ($healingPositions as $healingPos) {
            if (is_string($healingPos)) {
                $healingPos = FieldPlace::fromString($healingPos);
            }
            
            foreach ($availablePlaces->moveTo as $movePos) {
                if ($movePos->equals($healingPos)) {
                    // Direct move to healing fountain
                    $result = $this->apiClient->movePlayer(
                        $gameId,
                        $playerId,
                        $turnId,
                        $currentPosition->positionX,
                        $currentPosition->positionY,
                        $movePos->positionX,
                        $movePos->positionY,
                        false
                    );
                    
                    if ($result['success']) {
                        $this->logger->info("AI moved directly to healing fountain", [
                            'position' => $movePos->toString()
                        ]);
                        return true;
                    }
                }
            }
        }
        
        // If no direct path, move towards the nearest healing fountain
        $nearestFountain = $this->findNearestPosition($currentPosition, $healingPositions);
        
        if (!$nearestFountain) {
            return false;
        }
        
        // Find the move that gets us closest to the nearest fountain
        $bestMove = null;
        $minDistance = PHP_INT_MAX;
        
        foreach ($availablePlaces->moveTo as $movePos) {
            $distance = $this->calculateDistance($movePos, $nearestFountain);
            if ($distance < $minDistance) {
                $minDistance = $distance;
                $bestMove = $movePos;
            }
        }
        
        if ($bestMove && $minDistance < $this->calculateDistance($currentPosition, $nearestFountain)) {
            // This move gets us closer to the healing fountain
            $result = $this->apiClient->movePlayer(
                $gameId,
                $playerId,
                $turnId,
                $currentPosition->positionX,
                $currentPosition->positionY,
                $bestMove->positionX,
                $bestMove->positionY,
                false
            );
            
            if ($result['success']) {
                $this->logger->info("AI moved towards healing fountain", [
                    'target' => $nearestFountain->toString(),
                    'moved_to' => $bestMove->toString(),
                    'distance_remaining' => $minDistance
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Find beneficial movement opportunities
     */
    private function findBeneficialMovement(Uuid $playerId): ?array
    {
        if (!$this->currentField) {
            return null;
        }

        $availablePlaces = $this->messageBus->dispatch(
            new GetAvailablePlacesForPlayer($this->currentGame->gameId, $playerId)
        );

        foreach ($availablePlaces->moveTo as $position) {
            // Check for teleportation gates
            if ($this->isTeleportationGate($position)) {
                return [
                    'type' => 'teleport',
                    'to' => $position,
                    'endsAfterMove' => false,
                ];
            }

            // Check for healing fountains
            if ($this->isHealingFountain($position) && $this->needsHealing()) {
                return [
                    'type' => 'healing',
                    'to' => $position,
                    'endsAfterMove' => true,
                ];
            }

            // Check for valuable items
            if ($this->hasValuableItem($position)) {
                return [
                    'type' => 'collect_item',
                    'to' => $position,
                    'endsAfterMove' => true,
                ];
            }
        }

        return null;
    }

    /**
     * Execute movement
     */
    private function executeMovement(Uuid $gameId, Uuid $playerId, Uuid $turnId, array $movement): bool
    {
        $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        $targetPosition = $movement['to'];

        $result = $this->apiClient->movePlayer(
            $gameId,
            $playerId,
            $turnId,
            $currentPosition->positionX,
            $currentPosition->positionY,
            $targetPosition->positionX,
            $targetPosition->positionY,
            false
        );

        if ($result['success']) {
            // Check if battle occurred during movement
            if (isset($result['response']['battleInfo'])) {
                $battleInfo = $result['response']['battleInfo'];
                $this->handleBattle($gameId, $playerId, $turnId, $battleInfo);
                
                // If we won and there's a reward, pick it up immediately
                if ($battleInfo['result'] === 'win' && isset($battleInfo['reward'])) {
                    $position = FieldPlace::fromString($battleInfo['position']);
                    $pickResult = $this->apiClient->pickItem(
                        $gameId,
                        $playerId,
                        $turnId,
                        $position->positionX,
                        $position->positionY
                    );
                    
                    if ($pickResult['success']) {
                        $this->logger->info("AI picked up battle reward after movement", [
                            'item' => $battleInfo['reward']['name'] ?? 'unknown',
                            'position' => $battleInfo['position']
                        ]);
                        
                        if (isset($pickResult['response']['inventoryFull']) && $pickResult['response']['inventoryFull']) {
                            $this->handleInventoryFull($gameId, $playerId, $turnId, $pickResult['response']);
                        }
                    }
                }
                
                // Store that battle occurred for the caller
                $this->lastBattleInfo = ['occurred' => true];
            }
        }

        return $result['success'];
    }

    /**
     * Execute movement-only turn
     */
    private function executeMovementOnlyTurn(Uuid $gameId, Uuid $playerId, Uuid $turnId): bool
    {
        $availablePlaces = $this->messageBus->dispatch(
            new GetAvailablePlacesForPlayer($gameId, $playerId)
        );

        if (empty($availablePlaces->moveTo)) {
            // No moves available, end turn
            return $this->endTurn($gameId, $playerId, $turnId);
        }

        // Choose best move
        $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        $bestMove = $this->chooseMovement($currentPosition, $availablePlaces->moveTo, $this->currentField, $this->currentPlayer);

        // Execute move
        $result = $this->apiClient->movePlayer(
            $gameId,
            $playerId,
            $turnId,
            $currentPosition->positionX,
            $currentPosition->positionY,
            $bestMove->positionX,
            $bestMove->positionY,
            false
        );

        if ($result['success']) {
            return $this->endTurn($gameId, $playerId, $turnId);
        }

        return false;
    }

    /**
     * Choose best tile placement position
     */
    private function chooseBestTilePlacement(array $availablePlaces): FieldPlace
    {
        // Simple strategy: prefer positions that expand the board
        // More sophisticated strategies could consider nearby items, players, etc.
        return $availablePlaces[0];
    }

    /**
     * Choose consumables to use
     */
    private function chooseConsumables(array $availableConsumables, array $battleInfo): array
    {
        $selectedIds = [];
        $monsterHp = $battleInfo['monster'] ?? 0;
        $currentDamage = $battleInfo['totalDamage'] ?? 0;

        foreach ($availableConsumables as $consumable) {
            $consumableDamage = $this->getConsumableDamage($consumable['type'] ?? '');
            
            if ($currentDamage + $consumableDamage > $monsterHp) {
                $selectedIds[] = Uuid::fromString($consumable['itemId']);
                break; // One consumable is enough
            }
        }

        return $selectedIds;
    }

    /**
     * Get damage value for consumable type
     */
    private function getConsumableDamage(string $type): int
    {
        return match ($type) {
            'fireball' => 9,
            default => 0,
        };
    }

    /**
     * Choose which item to replace
     */
    private function chooseItemToReplace(array $newItem, array $currentInventory): ?array
    {
        $newItemValue = $this->getItemValue($newItem);
        $lowestValueItem = null;
        $lowestValue = $newItemValue;

        foreach ($currentInventory as $item) {
            $itemValue = $this->getItemValue($item);
            if ($itemValue < $lowestValue) {
                $lowestValue = $itemValue;
                $lowestValueItem = $item;
            }
        }

        return $lowestValueItem;
    }

    /**
     * Get strategic value of an item
     */
    private function getItemValue(array $item): int
    {
        return match ($item['type'] ?? '') {
            'axe' => 3,
            'sword' => 2,
            'dagger' => 1,
            default => 0,
        };
    }

    /**
     * Evaluate position for tile placement
     */
    private function evaluatePosition(FieldPlace $position, Field $field, Uuid $playerId): float
    {
        $score = 0.0;

        // Prefer positions that expand the board
        $score += $this->calculateExpansionScore($position, $field);

        // Consider proximity to items
        $score += $this->calculateItemProximityScore($position, $field);

        // Consider strategic positioning
        $score += $this->calculateStrategicScore($position, $field, $playerId);

        return $score;
    }

    /**
     * Evaluate move position
     */
    private function evaluateMovePosition(FieldPlace $position, Field $field, Player $player): float
    {
        $score = 0.0;

        // Check for items at position
        if ($this->hasValuableItem($position)) {
            $score += 10.0;
        }

        // Check for healing fountain
        if ($this->isHealingFountain($position) && $player->hp < $player->maxHp) {
            $score += 15.0 * (1 - $player->hp / $player->maxHp);
        }

        // Check for teleportation gate
        if ($this->isTeleportationGate($position)) {
            $score += 5.0;
        }

        return $score;
    }

    /**
     * Calculate expansion score for position
     */
    private function calculateExpansionScore(FieldPlace $position, Field $field): float
    {
        // Positions that expand the board are valuable
        $currentSize = $field->size;
        
        if ($position->x < $currentSize['minX'] || $position->x > $currentSize['maxX']) {
            return 5.0;
        }
        
        if ($position->y < $currentSize['minY'] || $position->y > $currentSize['maxY']) {
            return 5.0;
        }
        
        return 0.0;
    }

    /**
     * Calculate item proximity score
     */
    private function calculateItemProximityScore(FieldPlace $position, Field $field): float
    {
        $score = 0.0;
        
        foreach ($field->items as $itemPosition => $item) {
            if (!$item['guardDefeated']) {
                continue;
            }
            
            $itemPos = FieldPlace::fromString($itemPosition);
            $distance = $this->calculateDistance($position, $itemPos);
            
            // Closer items are more valuable
            $score += 10.0 / max(1, $distance);
        }
        
        return $score;
    }

    /**
     * Calculate strategic score
     */
    private function calculateStrategicScore(FieldPlace $position, Field $field, Uuid $playerId): float
    {
        // This could consider other players' positions, board control, etc.
        return 0.0;
    }

    /**
     * Calculate distance between positions
     */
    private function calculateDistance(FieldPlace $pos1, FieldPlace $pos2): int
    {
        return abs($pos1->positionX - $pos2->positionX) + abs($pos1->positionY - $pos2->positionY);
    }

    /**
     * Find nearest position from a list
     */
    private function findNearestPosition(FieldPlace $from, array $positions): ?FieldPlace
    {
        $nearest = null;
        $minDistance = PHP_INT_MAX;

        foreach ($positions as $position) {
            if (is_string($position)) {
                $position = FieldPlace::fromString($position);
            }
            
            $distance = $this->calculateDistance($from, $position);
            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearest = $position;
            }
        }

        return $nearest;
    }

    /**
     * Check if position has teleportation gate
     */
    private function isTeleportationGate(FieldPlace $position): bool
    {
        if (!$this->currentField) {
            return false;
        }

        foreach ($this->currentField->tiles as $tile) {
            if ($tile->position->equals($position)) {
                return in_array('teleportation_gate', $tile->features);
            }
        }

        return false;
    }

    /**
     * Check if position has healing fountain
     */
    private function isHealingFountain(FieldPlace $position): bool
    {
        if (!$this->currentField) {
            return false;
        }

        $positionString = $position->toString();
        return in_array($positionString, $this->currentField->healingFountainPositions ?? []);
    }

    /**
     * Check if position has valuable item
     */
    private function hasValuableItem(FieldPlace $position): bool
    {
        if (!$this->currentField) {
            return false;
        }

        $positionString = $position->toString();
        if (isset($this->currentField->items[$positionString])) {
            $item = $this->currentField->items[$positionString];
            return !$item['locked'] && $item['guardDefeated'];
        }

        return false;
    }

    /**
     * Check if there's an item at the given position (for pickup after battle)
     */
    private function hasItemAtPosition(FieldPlace $position): bool
    {
        if (!$this->currentField) {
            return false;
        }

        $positionString = $position->toString();
        
        // Check if there's an item at this position
        if (isset($this->currentField->items[$positionString])) {
            $item = $this->currentField->items[$positionString];
            // Item should be available (guard defeated, not locked, not already picked up)
            return isset($item['guardDefeated']) && $item['guardDefeated'] && 
                   (!isset($item['locked']) || !$item['locked']) &&
                   (!isset($item['pickedUp']) || !$item['pickedUp']);
        }

        return false;
    }

    /**
     * Check if healing is reachable from current position
     */
    private function canReachHealing(): bool
    {
        if (!$this->currentField || !$this->currentGame) {
            return false;
        }

        // Find healing fountain positions
        $healingPositions = $this->currentField->healingFountainPositions ?? [];
        
        if (empty($healingPositions)) {
            return false;
        }

        // Get available movement positions
        $availablePlaces = $this->messageBus->dispatch(
            new GetAvailablePlacesForPlayer($this->currentGame->gameId, $this->currentPlayer->playerId)
        );

        if (empty($availablePlaces->moveTo)) {
            return false;
        }

        // Check if any healing fountain is in the available moves
        foreach ($healingPositions as $healingPos) {
            if (is_string($healingPos)) {
                $healingPos = FieldPlace::fromString($healingPos);
            }
            
            foreach ($availablePlaces->moveTo as $movePos) {
                if ($movePos->equals($healingPos)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Choose best exploration move based on strategy
     */
    private function chooseBestExplorationMove(array $availableMoves): ?FieldPlace
    {
        if (empty($availableMoves)) {
            return null;
        }

        $bestMove = null;
        $bestScore = -999;

        foreach ($availableMoves as $move) {
            $score = 0.0;

            // Check if this position reveals new areas
            if ($this->isUnexploredArea($move)) {
                $score += 8.0;
            }

            // Check for nearby items
            if ($this->hasNearbyItems($move)) {
                $score += 5.0;
            }

            // Check for teleportation gates (exploration advantage)
            if ($this->isTeleportationGate($move)) {
                $score += 3.0;
            }

            // Prefer moves that expand the map
            if ($this->expandsMap($move)) {
                $score += 6.0;
            }

            // Add some randomness for variety
            $score += mt_rand(0, 100) / 100;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMove = $move;
            }
        }

        return $bestMove;
    }

    /**
     * Check if position is in unexplored area
     */
    private function isUnexploredArea(FieldPlace $position): bool
    {
        if (!$this->currentField) {
            return true; // Assume unexplored if we don't have field info
        }

        // Check if there are tiles around this position
        $siblings = $position->getAllSiblingsBySides();
        $unexploredCount = 0;

        foreach ($siblings as $sibling) {
            $hassTile = false;
            foreach ($this->currentField->tiles as $tile) {
                if ($tile->position->equals($sibling)) {
                    $hassTile = true;
                    break;
                }
            }
            
            if (!$hassTile) {
                $unexploredCount++;
            }
        }

        // If at least 2 sides are unexplored, it's worth exploring
        return $unexploredCount >= 2;
    }

    /**
     * Check if position has items nearby
     */
    private function hasNearbyItems(FieldPlace $position): bool
    {
        if (!$this->currentField) {
            return false;
        }

        // Check adjacent positions for items
        $siblings = $position->getAllSiblingsBySides();
        
        foreach ($siblings as $sibling) {
            $siblingString = $sibling->toString();
            if (isset($this->currentField->items[$siblingString])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if move expands the map boundaries
     */
    private function expandsMap(FieldPlace $position): bool
    {
        if (!$this->currentField) {
            return true;
        }

        $currentSize = $this->currentField->size ?? ['minX' => 0, 'maxX' => 0, 'minY' => 0, 'maxY' => 0];
        
        return $position->positionX < $currentSize['minX'] || 
               $position->positionX > $currentSize['maxX'] ||
               $position->positionY < $currentSize['minY'] || 
               $position->positionY > $currentSize['maxY'];
    }

    /**
     * End turn
     */
    private function endTurn(Uuid $gameId, Uuid $playerId, Uuid $turnId): bool
    {
        $result = $this->apiClient->endTurn($gameId, $playerId, $turnId);
        
        // Log end turn action
        $this->actionLog[] = [
            'type' => 'end_turn',
            'timestamp' => date('H:i:s.v'),
            'details' => ['result' => $result]
        ];
        
        if ($result['success']) {
            $this->logger->info("AI turn ended successfully");
        } else {
            $this->logger->error("Failed to end AI turn", $result);
        }
        
        return $result['success'];
    }

    /**
     * Set strategy configuration
     */
    public function setStrategyConfig(array $config): void
    {
        $this->strategyConfig = array_merge($this->strategyConfig, $config);
    }

    /**
     * Get current strategy configuration
     */
    public function getStrategyConfig(): array
    {
        return $this->strategyConfig;
    }
}