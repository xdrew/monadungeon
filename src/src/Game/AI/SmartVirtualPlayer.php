<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\Deck\GetDeck;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\Field\GetField;
use App\Game\Field\TileFeature;
use App\Game\Field\TileSide;
use App\Game\GameLifecycle\GetGame;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\MessageBus\MessageBus;


/**
 * Smart Virtual AI player that plays like a human - uses atomic actions for clean gameplay
 */
final class SmartVirtualPlayer
{
    private array $strategyConfig = [];
    private array $collectedChests = [];  // Track chests we've already collected or tried
    private array $visitedPositions = [];  // Track visited positions in current turn to prevent oscillation
    private static array $unreachableTargets = [];  // Track targets that are unreachable (persists across turns)
    private static array $unreachableWeapons = [];  // Track weapons proven unreachable
    private static array $turnsSinceLastProgress = [];  // Track turns without progress per game/player
    private static array $pursuingDragon = [];
    private static array $dragonPath = []; // Store the planned path to dragon  // Track if we're currently pursuing the dragon boss
    private static array $lastFieldStateHash = [];  // Track field state to detect when new tiles are placed
    private static array $unPickableItems = [];  // Track items that couldn't be picked up due to full inventory
    private static array $persistentTargets = [];  // Track current target position across turns per game/player
    private static array $persistentTargetReasons = [];  // Track why we're pursuing targets across turns
    private static array $persistentMonsterTargets = [];  // Track monster targets being pursued within turn
    private static array $persistentPaths = [];  // Store calculated paths to targets across turns
    private static array $explorationTargets = [];  // Track exploration targets to prevent loops
    private static array $explorationHistory = [];  // Track positions explored across turns
    private int $moveCount = 0;  // Track number of moves in current turn
    
    // Strategic goal tracking
    private static array $currentGoal = [];  // Current strategic goal per game/player
    private static array $goalProgress = [];  // Track progress towards current goal
    private static array $previousPosition = [];  // Track where we came from to avoid oscillation per game/player
    private static array $oscillationDetector = [];  // Track last few positions to detect oscillation patterns
    
    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly VirtualPlayerStrategy $strategy,
        private readonly VirtualPlayerApiClient $apiClient,
    ) {}

    private const MAX_ACTIONS_PER_TURN = 30; // Safety limit to prevent infinite loops
    private const MAX_TILES_PER_SEQUENCE = 5; // Max tiles in one placement sequence (corridors)
    private const MAX_MOVES_PER_TURN = 4; // Maximum number of moves allowed in a single turn
    
    /**
     * Execute a complete turn for the virtual player
     * @param string $strategyType The strategy to use: 'aggressive', 'balanced', or 'defensive'
     */
    public function executeTurn(Uuid $gameId, Uuid $playerId, string $strategyType = 'balanced'): array
    {
        $actions = [];
        
        // Add immediate debug to confirm this method is called
        error_log("DEBUG: SmartVirtualPlayer::executeTurn called for game {$gameId->toString()} player {$playerId->toString()}");
        
        // Reset move counter at the start of each turn
        $this->moveCount = 0;
        
        // Keep visited positions within the turn to prevent loops
        // But also track recent positions across turns for oscillation detection
        $this->visitedPositions = [];
        
        // Don't clear paths entirely - we want persistence
        // The path validation will handle checking if steps are still valid
        
        try {
            // Check if game is already finished
            $game = $this->messageBus->dispatch(new GetGame($gameId));
            if ($game->getStatus()->isFinished()) {
                return [[
                    'type' => 'ai_skip', 
                    'details' => ['message' => 'Game is already finished, AI skipping turn'],
                    'timestamp' => date('H:i:s.v')
                ]];
            }
            
            // Apply strategy configuration
            $this->applyStrategy($strategyType);
            
            $actions[] = $this->createAction('ai_start', [
                'message' => 'Starting AI turn',
                'strategy' => $strategyType
            ]);
            
            // Reset visited positions for new turn to prevent oscillation
            $this->visitedPositions = [];
            
            // Initialize tracking for this game/player if needed
            $trackingKey = "{$gameId}_{$playerId}";
            if (!isset(self::$unreachableTargets[$trackingKey])) {
                self::$unreachableTargets[$trackingKey] = [];
            }
            if (!isset(self::$turnsSinceLastProgress[$trackingKey])) {
                self::$turnsSinceLastProgress[$trackingKey] = [];
            }
            if (!isset(self::$persistentTargets[$trackingKey])) {
                self::$persistentTargets[$trackingKey] = null;
            }
            if (!isset(self::$persistentTargetReasons[$trackingKey])) {
                self::$persistentTargetReasons[$trackingKey] = null;
            }
            if (!isset(self::$explorationTargets[$trackingKey])) {
                self::$explorationTargets[$trackingKey] = null;
            }
            if (!isset(self::$explorationHistory[$trackingKey])) {
                self::$explorationHistory[$trackingKey] = [];
            }
            
            // Check if field state has changed (new tiles placed)
            $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
            $currentFieldHash = md5(json_encode($field->getPlacedTiles()));
            
            if (isset(self::$lastFieldStateHash[$trackingKey]) && self::$lastFieldStateHash[$trackingKey] !== $currentFieldHash) {
                // Field has changed, clear unreachable targets as paths may have opened
                error_log("DEBUG AI: Field state changed, clearing unreachable targets and persistent target");
                self::$unreachableTargets[$trackingKey] = [];
                self::$turnsSinceLastProgress[$trackingKey] = [];
                self::$persistentTargets[$trackingKey] = null;
                self::$persistentTargetReasons[$trackingKey] = null;
                self::$explorationTargets[$trackingKey] = null;
                self::$explorationHistory[$trackingKey] = [];  // Clear exploration history when new tiles are placed
            }
            
            // Also periodically clear unreachable targets every 5 turns to re-evaluate
            $game = $this->messageBus->dispatch(new GetGame($gameId));
            $turnNumber = $game->getCurrentTurnNumber();
            if ($turnNumber > 0 && $turnNumber % 5 === 0) {
                error_log("DEBUG AI: Turn {$turnNumber} - periodic clear of unreachable targets");
                self::$unreachableTargets[$trackingKey] = [];
                self::$turnsSinceLastProgress[$trackingKey] = [];
            }
            
            self::$lastFieldStateHash[$trackingKey] = $currentFieldHash;
            
            // Get current turn ID
            $currentTurnId = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
            if (!$currentTurnId) {
                return [['type' => 'ai_error', 'error' => 'No current turn found']];
            }
            
            // Get current game state
            $field = $this->messageBus->dispatch(new GetField($gameId));
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
            
            // Check if player is stunned (HP = 0 but not defeated)
            if ($player->getHP() === 0 && !$player->isDefeated()) {
                $actions[] = $this->createAction('ai_info', ['message' => 'Player is stunned, skipping turn']);
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                return $actions;
            }
            
            if ($player->isDefeated()) {
                $actions[] = $this->createAction('ai_info', ['message' => 'Player is defeated, ending turn']);
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                return $actions;
            }
            
            // Get current position and available actions
            $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
            
            // Check if current position has a tile
            $field = $this->messageBus->dispatch(new GetField($gameId));
            $placedTiles = $field->getPlacedTiles();
            if (!isset($placedTiles[$currentPosition->toString()])) {
                error_log("CRITICAL AI ERROR: Player is at position {$currentPosition->toString()} which has no tile!");
                $actions[] = $this->createAction('ai_error', [
                    'message' => "Player is at invalid position {$currentPosition->toString()} with no tile",
                    'critical' => true
                ]);
                
                // Try to place a tile at current position if possible
                $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                    gameId: $gameId,
                    playerId: $playerId,
                    messageBus: $this->messageBus,
                ));
                $placeTileOptions = $availablePlaces['placeTile'] ?? [];
                
                if (in_array($currentPosition->toString(), $placeTileOptions)) {
                    $actions[] = $this->createAction('ai_recovery', [
                        'message' => 'Attempting to place tile at current invalid position'
                    ]);
                    $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, [$currentPosition->toString()], $actions, 0);
                    return $actions;
                } else {
                    // Can't recover, end turn
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    return $actions;
                }
            }
            
            // Mark current position as visited to prevent returning to it
            $this->visitedPositions[$currentPosition->toString()] = true;
            
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            
            $moveToOptions = $availablePlaces['moveTo'] ?? [];
            $placeTileOptions = $availablePlaces['placeTile'] ?? [];
            
            // Validate moveToOptions - ensure they actually have tiles
            $placedTiles = $field->getPlacedTiles();
            $validMoveOptions = [];
            foreach ($moveToOptions as $moveOption) {
                if (isset($placedTiles[$moveOption])) {
                    $validMoveOptions[] = $moveOption;
                } else {
                    error_log("DEBUG AI: WARNING - Move option {$moveOption} has no tile! Filtering out.");
                }
            }
            $moveToOptions = $validMoveOptions;
            
            // Debug: Log available options
            $hasKey = $this->playerHasKey($player);
            
            // Get all items on the field
            $items = $field->getItems();
            
            // Process items to find chests
            foreach ($items as $pos => $item) {
                if ($item instanceof \App\Game\Item\Item) {
                    $itemType = $item->type->value ?? 'unknown';
                    $itemName = $item->name->value ?? 'unknown';
                    
                    // Check if this is a chest
                    if ($itemType === 'chest' || $itemName === 'treasure_chest' || 
                        ($item->guardHP === 0 && $item->treasureValue > 0)) {
                        // This is a chest
                    }
                } elseif (is_array($item)) {
                    error_log("DEBUG AI: Item at {$pos} (array): " . json_encode($item));
                }
            }
            
            // Check if any visible positions have chests
            $visibleChests = [];
            // First check moveToOptions
            foreach ($moveToOptions as $pos) {
                if ($this->positionHasChest($pos, $field)) {
                    $visibleChests[] = $pos;
                    error_log("DEBUG AI: Found chest at moveable position: {$pos}");
                    
                    // If we have a key and can move to a chest, this should be highest priority!
                    if ($hasKey) {
                        error_log("DEBUG AI: PRIORITY ACTION - Have key and can reach chest at {$pos}!");
                    }
                }
            }
            
            // Also check ALL positions on field for chests (for debugging)
            $allChestsOnField = [];
            foreach ($items as $pos => $item) {
                if ($this->isChestItem($item)) {
                    $allChestsOnField[] = $pos;
                    error_log("DEBUG AI: Found chest on field at: {$pos}");
                }
            }
            
            if (!empty($allChestsOnField) && empty($visibleChests)) {
                error_log("DEBUG AI: WARNING - Chests exist on field but not in move options!");
                error_log("DEBUG AI: Chests at: " . json_encode($allChestsOnField));
                error_log("DEBUG AI: Move options: " . json_encode($moveToOptions));
            }
            
            // Check for better weapons we can move to immediately
            $betterWeaponsImmediate = [];
            foreach ($moveToOptions as $pos) {
                if (isset($items[$pos])) {
                    $item = $items[$pos];
                    if ($this->shouldPickupItem($item, $player)) {
                        $itemType = '';
                        if ($item instanceof \App\Game\Item\Item) {
                            $itemType = $item->type->value ?? '';
                        }
                        // Only add weapons that are truly worth picking up
                        if (in_array($itemType, ['dagger', 'sword', 'axe'])) {
                            $betterWeaponsImmediate[$pos] = $itemType;
                            error_log("DEBUG AI: Found weapon worth picking up at {$pos}: {$itemType} (can reach this turn)");
                        }
                    }
                }
            }
            
            // Also check for better weapons anywhere on the field that we should move towards
            $betterWeaponsOnField = [];
            $trackingKey = "{$gameId}_{$playerId}";
            foreach ($items as $pos => $item) {
                // Skip if this target is marked as unreachable
                if (isset(self::$unreachableTargets[$trackingKey][$pos])) {
                    $itemType = 'unknown';
                    if ($item instanceof \App\Game\Item\Item) {
                        $itemType = $item->type->value ?? 'unknown';
                    }
                    error_log("DEBUG AI: Skipping previously unreachable {$itemType} at {$pos} (will retry periodically)");
                    continue;
                }
                
                // Skip if this item is marked as unpickable (inventory full)
                if (isset(self::$unPickableItems[$trackingKey][$pos])) {
                    $itemType = 'unknown';
                    if ($item instanceof \App\Game\Item\Item) {
                        $itemType = $item->type->value ?? 'unknown';
                    }
                    error_log("DEBUG AI: Skipping unpickable {$itemType} at {$pos} (inventory full)");
                    continue;
                }
                
                if ($this->shouldPickupItem($item, $player)) {
                    $itemType = '';
                    if ($item instanceof \App\Game\Item\Item) {
                        $itemType = $item->type->value ?? '';
                    }
                    // Only add weapons that are truly worth picking up
                    if (in_array($itemType, ['dagger', 'sword', 'axe'])) {
                        $betterWeaponsOnField[$pos] = $itemType;
                        error_log("DEBUG AI: Weapon worth picking up on field at {$pos}: {$itemType}");
                    }
                }
            }
            
            // Find all monsters on the field
            $monstersOnField = [];
            foreach ($items as $pos => $item) {
                if ($item instanceof \App\Game\Item\Item) {
                    // Check if this is a monster (has HP and not defeated)
                    if ($item->guardHP > 0 && !$item->guardDefeated) {
                        $monsterInfo = [
                            'position' => $pos,
                            'name' => $item->name->value ?? 'unknown',
                            'type' => $item->type->value ?? 'unknown',
                            'hp' => $item->guardHP,
                            'locked' => $item->isLocked()
                        ];
                        
                        // Mark if this is the dragon boss
                        if ($item->name->value === 'dragon' || $item->type->value === 'ruby_chest') {
                            $monsterInfo['isBoss'] = true;
                        }
                        
                        $monstersOnField[] = $monsterInfo;
                    }
                }
            }
            
            // Check if deck is empty to adjust tileOptions
            $deck = $this->messageBus->dispatch(new GetDeck($gameId));
            $actualTileOptions = $deck->isEmpty() ? 0 : count($placeTileOptions);
            
            $actions[] = $this->createAction('ai_options', [
                'moveOptions' => count($moveToOptions),
                'tileOptions' => $actualTileOptions,
                'hasKey' => $hasKey,
                'currentPosition' => $currentPosition->toString(),
                'visibleChests' => $visibleChests,
                'chestCount' => count($visibleChests),
                'chestsOnField' => $allChestsOnField,  // All chests on the field
                'betterWeaponsImmediate' => $betterWeaponsImmediate,
                'betterWeaponsOnField' => $betterWeaponsOnField,
                'monstersOnField' => $monstersOnField,  // All monsters including dragon
                'deckEmpty' => $deck->isEmpty()  // Add deck status
            ]);
            
            // PRIORITY -1: Check if we're on a healing fountain and need healing
            $currentPosStr = $currentPosition->toString();
            $currentHP = $player->getHP();
            $maxHP = $player->getMaxHP();
            
            // Check if we're on a healing fountain and need healing (HP < max)
            if ($currentHP < $maxHP && $this->isOnHealingFountain($currentPosStr, $field)) {
                $actions[] = $this->createAction('ai_reasoning', [
                    'decision' => 'End turn on healing fountain',
                    'reason' => "On healing fountain with {$currentHP}/{$maxHP} HP - ending turn to heal",
                    'priority' => -1
                ]);
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                return $actions;
            }
            
            // PRIORITY 0: Check if we're standing on an item we should pick up!
            $items = $field->getItems();
            
            // Check for chest first (highest priority)
            if ($hasKey && $this->positionHasChest($currentPosStr, $field)) {
                $actions[] = $this->createAction('treasure_on_current_tile', ['message' => 'Standing on chest with key!']);
                $this->pickupChestAtCurrentPosition($gameId, $playerId, $currentTurnId, $currentPosition, $actions);
                return $actions;
            }
            
            // Check for other items (weapons, etc.) at current position
            if (isset($items[$currentPosStr])) {
                $item = $items[$currentPosStr];
                
                // Check if we've already tried and failed to pick up this item
                $trackingKey = "{$gameId}_{$playerId}";
                if (isset(self::$unPickableItems[$trackingKey][$currentPosStr])) {
                    $actions[] = $this->createAction('ai_info', ['message' => 'Skipping item at current position - already tried to pick up with full inventory']);
                } 
                // Check if this is a defeated monster with loot we haven't picked up
                elseif ($this->shouldPickupItem($item, $player)) {
                    $actions[] = $this->createAction('ai_reasoning', ['message' => 'Standing on item from previous battle - picking it up!']);
                    
                    [$x, $y] = explode(',', $currentPosStr);
                    $pickupResult = $this->apiClient->pickItem($gameId, $playerId, $currentTurnId, (int)$x, (int)$y);
                    $actions[] = $this->createAction('pickup_attempt', ['result' => $pickupResult]);
                    
                    if ($pickupResult['success']) {
                        $response = $pickupResult['response'] ?? [];
                        
                        // Check if inventory became full and we need to replace
                        if (isset($response['inventoryFull']) && $response['inventoryFull'] === true) {
                            // Check if item was actually replaced
                            $itemReplaced = $response['itemReplaced'] ?? false;
                            
                            if (!$itemReplaced) {
                                // Item was NOT picked up due to full inventory
                                $actions[] = $this->createAction('ai_info', ['message' => 'Inventory full, item not picked up - continuing turn']);
                                // Mark this item position as unpickable
                                $trackingKey = "{$gameId}_{$playerId}";
                                if (!isset(self::$unPickableItems[$trackingKey])) {
                                    self::$unPickableItems[$trackingKey] = [];
                                }
                                self::$unPickableItems[$trackingKey][$currentPosStr] = true;
                                // Continue with the turn instead of ending it
                            } else {
                                // Item was replaced, we can end the turn
                                $actions[] = $this->createAction('ai_info', ['message' => 'Item replaced in inventory, ending turn']);
                                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                                return $actions;
                            }
                        } else {
                            // Item was picked up successfully (inventory not full)
                            $actions[] = $this->createAction('ai_info', ['message' => 'Item picked up, ending turn']);
                            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                            return $actions;
                        }
                    }
                }
            }
            
            // PRIORITY 0.5: Critical HP - must find healing fountain immediately  
            if ($player->getHP() <= 1) {
                // First check if healing fountain is in immediate move options
                $healingFountainPosition = $this->findHealingFountainInMoveOptions($moveToOptions, $field);
                
                if ($healingFountainPosition !== null) {
                    // Can reach fountain immediately
                    $actions[] = $this->createAction('ai_reasoning', [
                        'decision' => 'Move to healing fountain (critical HP)',
                        'reason' => "HP is 1 - must heal immediately at fountain at {$healingFountainPosition}",
                        'priority' => 0.5
                    ]);
                    
                    // Move directly to the healing fountain
                    [$toX, $toY] = explode(',', $healingFountainPosition);
                    [$fromX, $fromY] = explode(',', $currentPosition->toString());
                    $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                    $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                    
                    $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                    return $actions;
                } else if (!empty($moveToOptions)) {
                    // Can't reach fountain immediately, but need to move towards it
                    // The main healing fountain is always at 0,0
                    $healingFountainTarget = '0,0';
                    
                    $actions[] = $this->createAction('ai_reasoning', [
                        'decision' => 'Move towards healing fountain (critical HP)',
                        'reason' => "HP is 1 - moving towards healing fountain at {$healingFountainTarget}",
                        'priority' => 0.5
                    ]);
                    
                    // Find the move option that gets us closest to the healing fountain
                    $bestMove = null;
                    $minDistance = PHP_INT_MAX;
                    foreach ($moveToOptions as $moveOption) {
                        $distance = $this->calculateManhattanDistance($moveOption, $healingFountainTarget);
                        if ($distance < $minDistance) {
                            $minDistance = $distance;
                            $bestMove = $moveOption;
                        }
                    }
                    
                    if ($bestMove !== null) {
                        [$toX, $toY] = explode(',', $bestMove);
                        [$fromX, $fromY] = explode(',', $currentPosition->toString());
                        
                        $actions[] = $this->createAction('critical_hp_movement', [
                            'message' => "Moving towards healing fountain",
                            'from' => $currentPosition->toString(),
                            'to' => $bestMove,
                            'distance_to_fountain' => $minDistance
                        ]);
                        
                        $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                        $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                        
                        $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                        return $actions;
                    }
                }
            }
            
            // Determine strategic goal for this turn
            $strategicGoal = $this->determineStrategicGoal($gameId, $playerId);
            $actions[] = $this->createAction('strategic_goal', [
                'type' => $strategicGoal['type'],
                'target' => $strategicGoal['target'] ?? null,
                'reason' => $strategicGoal['reason'],
                'priority' => $strategicGoal['priority']
            ]);
            
            // Update goal tracking
            $trackingKey = "{$gameId}_{$playerId}";
            self::$currentGoal[$trackingKey] = $strategicGoal;
            
            // PRIORITY 0.6: Check if we can win the game by defeating the dragon boss
            $dragonInfo = $this->findDragonBoss($field);
            
            if ($dragonInfo !== null) {
                $dragonPosition = $dragonInfo['position'];
                $dragonHP = $dragonInfo['hp'];
                $playerStrength = $this->calculateEffectiveStrength($player);
                
                // For dragon, also consider consumables we could use
                $inventory = $player->getInventory();
                $availableFireballs = 0;
                foreach ($inventory['spell'] ?? [] as $spell) {
                    if ($spell instanceof \App\Game\Item\Item) {
                        if ($spell->type->value === 'fireball') {
                            $availableFireballs++;
                        }
                    } elseif (is_array($spell)) {
                        if (($spell['type'] ?? '') === 'fireball' && ($spell['uses'] ?? 0) > 0) {
                            $availableFireballs += $spell['uses'];
                        }
                    }
                }
                
                // Calculate strength including consumables for dragon fight
                $strengthWithConsumables = $playerStrength + $availableFireballs;
                
                // Check if dragon is the only monster left on the field
                $otherMonstersExist = false;
                foreach ($monstersOnField as $monster) {
                    if ($monster['position'] !== $dragonPosition) {
                        $otherMonstersExist = true;
                        break;
                    }
                }
                
                // If dragon is the only monster left AND deck is empty, we should try even if odds are low
                // Because there's no other way to improve our situation
                $shouldAttemptDragon = false;
                $shouldPursue = false; // Track if we should pursue even if we can't win
                
                if (!$otherMonstersExist && $deck->isEmpty()) {
                    // Maximum possible roll with 2d6 + weapons could still win
                    $maxPossibleDamage = 12 + ($playerStrength - 7) + $availableFireballs; // 12 from dice + weapons + fireballs
                    if ($maxPossibleDamage >= $dragonHP) {
                        $shouldAttemptDragon = true;
                        $shouldPursue = true;
                        error_log("DEBUG AI: Dragon is the only monster and deck is empty. Will attempt even with low odds.");
                    } else {
                        // Can't win even with max rolls, but should still move toward it if it's the ONLY option
                        $shouldPursue = true; // Still pursue to try our luck
                        error_log("DEBUG AI: Dragon is impossible to defeat but it's the only option. Moving toward it anyway.");
                    }
                } else {
                    // Normal case - only attempt if we have reasonable chance
                    $shouldAttemptDragon = ($strengthWithConsumables >= $dragonHP);
                    if ($shouldAttemptDragon) {
                        $shouldPursue = true;
                    }
                }
                
                // Set pursuit flag if we should pursue the dragon
                if ($shouldPursue) {
                    $trackingKey = "{$gameId}_{$playerId}";
                    self::$pursuingDragon[$trackingKey] = $dragonPosition;
                    error_log("DEBUG AI: Setting dragon pursuit flag for position {$dragonPosition}");
                }
                
                // Check if we can defeat the dragon (with or without consumables)
                if ($shouldAttemptDragon) {
                    // Check if defeating the dragon would give us victory
                    $currentChestScore = $this->calculateChestScore($player);
                    $opponentScores = $this->getOpponentChestScores($gameId, $playerId);
                    $maxOpponentScore = !empty($opponentScores) ? max($opponentScores) : 0;
                    
                    // Dragon drops ruby chest worth 3 points
                    $scoreAfterDragon = $currentChestScore + 3;
                    
                    // Calculate scores for victory determination
                    
                    // If defeating dragon would win or we're already ahead, prioritize it
                    if ($scoreAfterDragon > $maxOpponentScore || $currentChestScore >= $maxOpponentScore) {
                        // Defeating dragon would win the game!
                        
                        // Check if we're adjacent to the dragon
                        $adjacentPositions = $this->getAdjacentPositions($dragonPosition);
                        $currentPosStr = $currentPosition->toString();
                        
                        if (in_array($currentPosStr, $adjacentPositions)) {
                            // We're adjacent! Attack the dragon
                            $attackReason = $strengthWithConsumables >= $dragonHP 
                                ? "Can defeat dragon (HP: {$dragonHP}, Strength: {$playerStrength}) for victory!"
                                : "Last chance - attempting dragon (HP: {$dragonHP}, Strength: {$playerStrength}) as only option!";
                            
                            $actions[] = $this->createAction('ai_reasoning', [
                                'decision' => 'Attack dragon boss to win game',
                                'reason' => $attackReason,
                                'priority' => 0.6
                            ]);
                            
                            // Clear dragon pursuit flag as we're attacking
                            $trackingKey = "{$gameId}_{$playerId}";
                            self::$pursuingDragon[$trackingKey] = false;
                            
                            // Move to dragon position to attack
                            [$toX, $toY] = explode(',', $dragonPosition);
                            [$fromX, $fromY] = explode(',', $currentPosStr);
                            $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                            $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                            
                            $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                            return $actions;
                        } else {
                            // Need to move closer to dragon
                            
                            // Mark that we're pursuing the dragon
                            $trackingKey = "{$gameId}_{$playerId}";
                            self::$pursuingDragon[$trackingKey] = $dragonPosition;
                            
                            // Plan path to dragon to avoid oscillation
                            $placedTiles = $field->getPlacedTiles();
                            error_log("DEBUG AI: Checking for existing path. Key: {$trackingKey}");
                            if (!isset(self::$dragonPath[$trackingKey]) || empty(self::$dragonPath[$trackingKey])) {
                                error_log("DEBUG AI: No existing path found. Planning initial path to dragon at {$dragonPosition} from {$currentPosStr}");
                                $path = $this->findPathToTarget($currentPosStr, $dragonPosition, $placedTiles, $field);
                                if (!empty($path)) {
                                    self::$dragonPath[$trackingKey] = $path;
                                    error_log("DEBUG AI: Successfully planned path to dragon with " . count($path) . " steps: " . implode(' -> ', $path));
                                    // Also log as action for visibility
                                    $actions[] = $this->createAction('ai_info', [
                                        'message' => "Planned path to dragon: " . implode(' -> ', array_slice($path, 0, 5)) . (count($path) > 5 ? '...' : '')
                                    ]);
                                } else {
                                    error_log("DEBUG AI: FAILED to find path to dragon! Dragon at {$dragonPosition}, current at {$currentPosStr}");
                                    $actions[] = $this->createAction('ai_info', ['message' => 'Could not find path to dragon!']);
                                }
                            } else {
                                error_log("DEBUG AI: Using existing path with " . count(self::$dragonPath[$trackingKey]) . " steps remaining");
                            }
                            
                            // Get next move from planned path
                            $bestMoveTowardDragon = null;
                            if (isset(self::$dragonPath[$trackingKey]) && !empty(self::$dragonPath[$trackingKey])) {
                                $nextStep = self::$dragonPath[$trackingKey][0]; // Peek at next step
                                error_log("DEBUG AI: Next planned step is {$nextStep}, available moves: " . implode(', ', $moveToOptions));
                                
                                if (in_array($nextStep, $moveToOptions)) {
                                    array_shift(self::$dragonPath[$trackingKey]); // Remove from path
                                    $bestMoveTowardDragon = $nextStep;
                                    error_log("DEBUG AI: Following planned path, moving to {$bestMoveTowardDragon}. " . count(self::$dragonPath[$trackingKey]) . " steps remaining.");
                                    $actions[] = $this->createAction('ai_info', [
                                        'message' => "Following path: moving to {$bestMoveTowardDragon}"
                                    ]);
                                } else {
                                    // Path blocked, replan
                                    error_log("DEBUG AI: Planned step {$nextStep} not available in moves! Clearing path and using fallback.");
                                    unset(self::$dragonPath[$trackingKey]);
                                    $bestMoveTowardDragon = $this->findBestMoveToward($currentPosStr, $dragonPosition, $moveToOptions, $placedTiles);
                                    $actions[] = $this->createAction('ai_info', ['message' => 'Path blocked, using direct pathfinding']);
                                }
                            } else {
                                // No path, use simple pathfinding
                                error_log("DEBUG AI: No planned path exists, using simple pathfinding");
                                $bestMoveTowardDragon = $this->findBestMoveToward($currentPosStr, $dragonPosition, $moveToOptions, $placedTiles);
                                $actions[] = $this->createAction('ai_info', ['message' => 'No path planned, using direct movement']);
                            }
                            
                            if ($bestMoveTowardDragon !== null) {
                                $moveReason = $strengthWithConsumables >= $dragonHP
                                    ? "Moving closer to dragon to attack and win game"
                                    : "Moving toward dragon - last chance as only monster remaining";
                                
                                $actions[] = $this->createAction('ai_reasoning', [
                                    'decision' => 'Move toward dragon boss',
                                    'reason' => $moveReason,
                                    'priority' => 0.6
                                ]);
                                
                                [$toX, $toY] = explode(',', $bestMoveTowardDragon);
                                [$fromX, $fromY] = explode(',', $currentPosStr);
                                $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                                $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                                
                                $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                                return $actions;
                            }
                        }
                    }
                } else {
                    // Can't defeat dragon yet, but note its presence
                    $fireballInfo = $availableFireballs > 0 ? " (+{$availableFireballs} fireballs = " . ($playerStrength + $availableFireballs) . " total)" : "";
                    
                    // Check if it's impossible even with max rolls
                    $maxPossibleDamage = 12 + ($playerStrength - 7) + $availableFireballs;
                    $impossibleMessage = $maxPossibleDamage < $dragonHP 
                        ? " (impossible even with max rolls: {$maxPossibleDamage} < {$dragonHP})"
                        : "";
                    
                    $actions[] = $this->createAction('ai_info', [
                        'message' => "Dragon found at {$dragonPosition} but can't defeat it yet (HP: {$dragonHP}, Strength: {$playerStrength}{$fireballInfo}){$impossibleMessage}"
                    ]);
                }
            }
            
            // Decide action based on strategy: movement or tile placement
            // PRIORITY 1: Always move to treasures if we have a key
            // PRIORITY 2: Move to or towards better weapons
            // PRIORITY 3: Healing if needed
            // PRIORITY 4: Place tiles or move to explore
            
            // Check if we should move towards chests or better weapons even if not immediately reachable
            $shouldMoveTowardsChest = false;
            $shouldMoveTowardsBetterWeapon = false;
            $shouldAttackMonsterForItem = false;
            $targetMonster = null;
            
            // Priority 1: Move towards chests if we have a key
            if ($hasKey && !empty($allChestsOnField) && empty($visibleChests)) {
                $shouldMoveTowardsChest = true;
                error_log("DEBUG AI: Have key and chests on field - should move towards them!");
            }
            
            // Priority 2: Move towards better weapons that are already available (guard defeated)
            if (!$shouldMoveTowardsChest && !empty($betterWeaponsOnField) && empty($betterWeaponsImmediate)) {
                // We have better weapons on field but can't reach them immediately
                // We should move towards them if we can
                $shouldMoveTowardsBetterWeapon = true;
                error_log("DEBUG AI: Should move towards better weapons on field");
            }
            
            // Priority 3: Consider ALL monsters guarding valuable items on the field
            $shouldMoveTowardsMonster = false;
            $targetMonsterToMoveTowards = null;
            
            // Always evaluate monsters, even if we're going for chests - the multi-objective pathfinding will optimize
            if (!empty($monstersOnField)) {
                // First check if any monsters are immediately reachable
                $reachableMonsters = [];
                $valuableDistantMonsters = [];
                
                foreach ($monstersOnField as $monster) {
                    $monsterPos = $monster['position'];
                    
                    // Check if the item is worth attacking for
                    if ($this->isItemWorthAttackingFor($monster['type'], $player)) {
                        // Check if we can reach this monster immediately
                        $isReachable = false;
                        foreach ($moveToOptions as $moveOption) {
                            if ($moveOption === $monsterPos) {
                                $isReachable = true;
                                break;
                            }
                        }
                        
                        if ($isReachable) {
                            $reachableMonsters[] = $monster;
                            error_log("DEBUG AI: Can immediately attack {$monster['name']} at {$monsterPos} for {$monster['type']}");
                        } else {
                            $valuableDistantMonsters[] = $monster;
                            error_log("DEBUG AI: Valuable monster {$monster['name']} at {$monsterPos} for {$monster['type']} (not immediately reachable)");
                        }
                    }
                }
                
                // If we can immediately attack a valuable monster, do it
                if (!empty($reachableMonsters)) {
                    $targetMonster = $this->chooseBestMonsterTarget($reachableMonsters, $player);
                    if ($targetMonster) {
                        $shouldAttackMonsterForItem = true;
                        error_log("DEBUG AI: Will immediately attack {$targetMonster['name']} for {$targetMonster['type']}");
                    }
                }
                // Otherwise, consider moving towards valuable distant monsters
                else if (!empty($valuableDistantMonsters)) {
                    $targetMonsterToMoveTowards = $this->chooseBestMonsterTarget($valuableDistantMonsters, $player);
                    if ($targetMonsterToMoveTowards) {
                        $shouldMoveTowardsMonster = true;
                        error_log("DEBUG AI: Will move towards {$targetMonsterToMoveTowards['name']} at {$targetMonsterToMoveTowards['position']} for {$targetMonsterToMoveTowards['type']}");
                    }
                }
            }
            
            if ($this->shouldMoveBeforePlacingTile($player, $field, $moveToOptions) || 
                (!empty($betterWeaponsImmediate)) ||
                ($shouldMoveTowardsChest && !empty($moveToOptions)) ||
                ($shouldAttackMonsterForItem && $targetMonster) ||
                ($shouldMoveTowardsBetterWeapon && !empty($moveToOptions)) ||
                ($shouldMoveTowardsMonster && $targetMonsterToMoveTowards && !empty($moveToOptions))) {
                
                $reason = $this->getMovementReason($player, $field, $moveToOptions, $hasKey);
                
                // Update reason based on what we're moving towards (priority order)
                if ($shouldMoveTowardsChest) {
                    $reason = "Moving towards chests on field (have key!): " . implode(', ', $allChestsOnField);
                } elseif ($shouldMoveTowardsBetterWeapon && empty($betterWeaponsImmediate)) {
                    $reason = "Collecting available weapons on field: " . implode(', ', $betterWeaponsOnField);
                } elseif ($shouldAttackMonsterForItem && $targetMonster) {
                    $reason = "Attacking {$targetMonster['name']} to get {$targetMonster['type']}";
                } elseif ($shouldMoveTowardsMonster && $targetMonsterToMoveTowards) {
                    $reason = "Moving towards {$targetMonsterToMoveTowards['name']} at {$targetMonsterToMoveTowards['position']} to get {$targetMonsterToMoveTowards['type']}";
                }
                
                $actions[] = $this->createAction('ai_reasoning', [
                    'decision' => 'Move to existing tile',
                    'reason' => $reason,
                    'priority' => 1
                ]);
                
                // Choose movement direction based on priority
                if ($shouldMoveTowardsChest) {
                    $this->executeMoveTowardsTarget($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $allChestsOnField, 'chest', $actions);
                } elseif ($shouldMoveTowardsBetterWeapon) {
                    $this->executeMoveTowardsBetterWeapon($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $betterWeaponsOnField, $actions);
                } elseif ($shouldAttackMonsterForItem && $targetMonster) {
                    // Move to attack the monster immediately
                    $this->executeAttackMonster($gameId, $playerId, $currentTurnId, $currentPosition, $targetMonster, $actions);
                } elseif ($shouldMoveTowardsMonster && $targetMonsterToMoveTowards) {
                    // Move towards distant monster
                    $this->executeMoveTowardsMonster($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $targetMonsterToMoveTowards, $actions);
                } else {
                    // Use goal-oriented movement
                    $this->executeGoalOrientedMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $strategicGoal, $actions);
                }
            } elseif (!empty($placeTileOptions) && !$deck->isEmpty()) {
                // Explain why not moving to chests
                $notMovingReason = '';
                if ($hasKey && !empty($visibleChests)) {
                    $notMovingReason = ' (WARNING: Ignoring ' . count($visibleChests) . ' visible chests!)';
                } elseif ($hasKey) {
                    $notMovingReason = ' (have key but no chests visible)';
                } elseif (!empty($visibleChests)) {
                    $notMovingReason = ' (can see ' . count($visibleChests) . ' chests but no key)';
                }
                
                $actions[] = $this->createAction('ai_reasoning', [
                    'decision' => 'Place new tile',
                    'reason' => 'Exploring dungeon to find items and treasures' . $notMovingReason,
                    'priority' => 3,
                    'hp' => $player->getHP(),
                    'strategy' => $this->strategyConfig['preferBattles'] ?? true ? 'seeking battles' : 'avoiding battles'
                ]);
                $this->executeTilePlacementSequence($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions);
            } elseif (!empty($moveToOptions)) {
                // No tiles to place, but can move to explore
                $actions[] = $this->createAction('ai_reasoning', [
                    'decision' => 'Move to explore',
                    'reason' => 'No new tiles to place, moving to explore existing areas',
                    'priority' => 3
                ]);
                $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
            } else{
                $actions[] = $this->createAction('ai_decision', ['decision' => 'No actions available, ending turn']);
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            }
            
            return $actions;
            
        } catch (\Throwable $e) {
            $actions[] = $this->createAction('ai_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $actions;
        }
    }
    
    /**
     * Check if player should move before placing tiles based on strategy
     */
    private function shouldMoveBeforePlacingTile($player, $field, array $moveToOptions): bool
    {
        // PRIORITY 1: Check for treasure opportunities
        if ($this->shouldMoveToTreasure($player, $field, $moveToOptions)) {
            return true;
        }
        
        // PRIORITY 2: Check for better weapon upgrades we can pick up
        if ($this->shouldMoveToUpgradeWeapon($player, $field, $moveToOptions)) {
            return true;
        }
        
        $healingThreshold = $this->strategyConfig['healingThreshold'] ?? 2;
        
        // PRIORITY 3: Move to healing fountain if HP is below strategy threshold
        if ($player->getHP() <= $healingThreshold && !empty($moveToOptions)) {
            // In a real implementation, would check if any move options have healing
            // For now, prioritize healing when HP is low
            foreach ($moveToOptions as $position) {
                // Check if position has healing fountain or beneficial items
                // This is simplified - would need field feature checking
                if ($player->getHP() <= 1) {
                    return true; // Critical HP, must move
                }
            }
        }
        
        // PRIORITY 4: Aggressive strategy - seek battles if strong enough
        if (($this->strategyConfig['preferBattles'] ?? false) && !empty($moveToOptions)) {
            // Check if any move options have monsters we can defeat
            $effectiveStrength = $this->calculateEffectiveStrength($player);
            if ($effectiveStrength >= 5) { // Strong enough to fight
                return false; // Place tiles instead to find new battles
            }
        }
        
        return false;
    }
    
    /**
     * Check if there are better weapons available to pick up
     */
    private function shouldMoveToUpgradeWeapon($player, $field, array $moveOptions): bool
    {
        $inventory = $player->getInventory();
        
        // Get current weapon strengths - inventory structure is ['weapon' => [...], ...]
        $currentWeapons = [];
        $weapons = isset($inventory['weapon']) ? $inventory['weapon'] : [];
        foreach ($weapons as $weapon) {
            // Handle both array and Item object formats
            if ($weapon instanceof \App\Game\Item\Item) {
                $currentWeapons[] = $weapon->type->value ?? '';
            } else {
                $currentWeapons[] = $weapon['type'] ?? '';
            }
        }
        
        // If we have 2 axes (best weapons), no need to look for upgrades
        $axeCount = count(array_filter($currentWeapons, fn($w) => $w === 'axe'));
        if ($axeCount >= 2) {
            error_log("DEBUG AI: Already have {$axeCount} axes, no need for weapon upgrades");
            return false;
        }
        
        // Check if any move option has a better weapon we can defeat
        $weaponPriority = ['dagger' => 1, 'sword' => 2, 'axe' => 3];
        $worstWeaponPriority = 999;
        
        // Find our worst weapon
        foreach ($currentWeapons as $weapon) {
            $priority = $weaponPriority[$weapon] ?? 0;
            if ($priority < $worstWeaponPriority) {
                $worstWeaponPriority = $priority;
            }
        }
        
        // If inventory isn't full, any weapon is worth getting
        if (count($currentWeapons) < 2) {
            $worstWeaponPriority = 0;
        }
        
        // Check each position for better weapons
        $playerStrength = $this->calculateEffectiveStrength($player);
        $items = $field->getItems();
        
        foreach ($moveOptions as $position) {
            if (isset($items[$position])) {
                $item = $items[$position];
                
                // Check what reward this monster drops
                $monsterReward = $this->getMonsterReward($item);
                if ($monsterReward && in_array($monsterReward, ['dagger', 'sword', 'axe'])) {
                    $rewardPriority = $weaponPriority[$monsterReward] ?? 0;
                    
                    // Check if this is better than our worst weapon
                    if ($rewardPriority > $worstWeaponPriority) {
                        // Check if we can defeat this monster
                        $monsterHP = $this->getMonsterHP($item);
                        if ($monsterHP > 0 && $playerStrength >= $monsterHP) {
                            error_log("DEBUG AI: Found better weapon ({$monsterReward}) at {$position} that we can defeat!");
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get monster HP from item
     */
    private function getMonsterHP($item): int
    {
        if ($item instanceof \App\Game\Item\Item) {
            return $item->guardDefeated ? 0 : $item->guardHP;
        } elseif (is_array($item)) {
            if (isset($item['monster']['hp'])) {
                return (int)$item['monster']['hp'];
            }
            if (isset($item['guardHP'])) {
                return (int)$item['guardHP'];
            }
        }
        return 0;
    }
    
    /**
     * Check if we should pick up an item at current position
     */
    private function shouldPickupItem($item, $player): bool
    {
        // Check if it's an Item object
        if ($item instanceof \App\Game\Item\Item) {
            // If monster is defeated, the loot should be available
            if ($item->guardDefeated) {
                // Check if it's a valuable item type
                $itemType = $item->type->value ?? '';
                if (in_array($itemType, ['dagger', 'sword', 'axe', 'key', 'fireball', 'teleport', 'chest'])) {
                    error_log("DEBUG AI: Found defeated monster with {$itemType} loot at current position");
                    
                    // For weapons, check if it's an upgrade
                    if (in_array($itemType, ['dagger', 'sword', 'axe'])) {
                        // Always pick up if inventory not full
                        $inventory = $player->getInventory();
                        $weapons = isset($inventory['weapon']) ? $inventory['weapon'] : [];
                        $weaponCount = count($weapons);
                        $hasWeakerWeapon = false;
                        
                        $weaponPriority = ['dagger' => 1, 'sword' => 2, 'axe' => 3];
                        $newPriority = $weaponPriority[$itemType] ?? 0;
                        
                        foreach ($weapons as $weapon) {
                            // Handle both array and Item object formats
                            if ($weapon instanceof \App\Game\Item\Item) {
                                $weaponType = $weapon->type->value ?? '';
                            } else {
                                $weaponType = $weapon['type'] ?? '';
                            }
                            
                            $invPriority = $weaponPriority[$weaponType] ?? 0;
                            if ($invPriority < $newPriority) {
                                $hasWeakerWeapon = true;
                            }
                        }
                        
                        // Pick up if we have room or if it's better than what we have
                        if ($weaponCount < 2 || $hasWeakerWeapon) {
                            error_log("DEBUG AI: Should pick up {$itemType} - weaponCount: {$weaponCount}, hasWeakerWeapon: " . ($hasWeakerWeapon ? 'true' : 'false'));
                            return true;
                        } else {
                            error_log("DEBUG AI: Should NOT pick up {$itemType} - already have better or equal weapons");
                            return false;
                        }
                    } else if ($itemType === 'key') {
                        // Only pick up keys if we don't already have one
                        if ($this->playerHasKey($player)) {
                            error_log("DEBUG AI: Already have a key, not picking up another one");
                            return false;
                        }
                        error_log("DEBUG AI: Should pick up key - we don't have one yet");
                        return true;
                    } else if (in_array($itemType, ['fireball', 'teleport'])) {
                        // Check spell inventory capacity
                        $inventory = $player->getInventory();
                        $spells = isset($inventory['spell']) ? $inventory['spell'] : [];
                        $spellCount = count($spells);
                        
                        // Maximum 3 spells allowed
                        if ($spellCount >= 3) {
                            // Check if we should replace a weaker spell
                            $spellPriority = ['teleport' => 1, 'fireball' => 2];
                            $newPriority = $spellPriority[$itemType] ?? 0;
                            
                            // Check if we have weaker spells to replace
                            $hasWeakerSpell = false;
                            foreach ($spells as $spell) {
                                if ($spell instanceof \App\Game\Item\Item) {
                                    $spellType = $spell->type->value ?? '';
                                } else {
                                    $spellType = $spell['type'] ?? '';
                                }
                                
                                $invPriority = $spellPriority[$spellType] ?? 0;
                                if ($invPriority < $newPriority) {
                                    $hasWeakerSpell = true;
                                    break;
                                }
                            }
                            
                            if ($hasWeakerSpell) {
                                error_log("DEBUG AI: Should pick up {$itemType} spell - can replace weaker spell (have {$spellCount}/3)");
                                return true;
                            } else {
                                error_log("DEBUG AI: Should NOT pick up {$itemType} - already have 3 spells and none are weaker");
                                return false;
                            }
                        } else {
                            error_log("DEBUG AI: Should pick up {$itemType} spell - have room ({$spellCount}/3 spells)");
                            return true;
                        }
                    } else {
                        // Pick up other items (chests, etc.)
                        return true;
                    }
                }
            }
        } elseif (is_array($item)) {
            // Handle array format
            if (isset($item['guardDefeated']) && $item['guardDefeated'] === true) {
                if (isset($item['type']) && !isset($item['pickedUp'])) {
                    error_log("DEBUG AI: Found unpicked item at current position: {$item['type']}");
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if we should replace an item for a better one
     */
    private function shouldReplaceForBetterItem(array $newItem, array $currentInventory): bool
    {
        $newItemType = $newItem['type'] ?? '';
        
        if (in_array($newItemType, ['dagger', 'sword', 'axe'])) {
            $weaponPriority = ['dagger' => 1, 'sword' => 2, 'axe' => 3];
            $newPriority = $weaponPriority[$newItemType] ?? 0;
            
            // Check if we have weaker weapons
            foreach ($currentInventory as $invItem) {
                $invType = $invItem['type'] ?? '';
                $invPriority = $weaponPriority[$invType] ?? 0;
                
                if ($invPriority > 0 && $invPriority < $newPriority) {
                    return true; // We have a weaker weapon to replace
                }
            }
        }
        
        return false;
    }
    
    /**
     * Continue turn after non-battle action (like picking up items)
     */
    private function continueAfterNonBattleAction(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array &$actions): void
    {
        // Check if we're on a healing fountain and need healing
        $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
        $currentHP = $player->getHP();
        $maxHP = $player->getMaxHP();
        
        if ($currentHP < $maxHP) {
            $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
            $field = $this->messageBus->dispatch(new GetField($gameId));
            
            if ($this->isOnHealingFountain($currentPosition->toString(), $field)) {
                $actions[] = $this->createAction('ai_reasoning', [
                    'decision' => 'End turn on healing fountain',
                    'reason' => "On healing fountain with {$currentHP}/{$maxHP} HP - ending turn to heal",
                    'priority' => -1
                ]);
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                return;
            }
        }
        
        // Unlike battle actions, picking up items doesn't end movement
        // Get available actions from current position
        $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
            gameId: $gameId,
            playerId: $playerId,
            messageBus: $this->messageBus,
        ));
        
        $moveToOptions = $availablePlaces['moveTo'] ?? [];
        $placeTileOptions = $availablePlaces['placeTile'] ?? [];
        
        // Get current position after pickup
        $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        $field = $this->messageBus->dispatch(new GetField($gameId));
        $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
        
        // Continue with normal turn logic
        if ($this->shouldMoveBeforePlacingTile($player, $field, $moveToOptions)) {
            $hasKey = $this->playerHasKey($player);
            $reason = $this->getMovementReason($player, $field, $moveToOptions, $hasKey);
            $actions[] = $this->createAction('ai_reasoning', [
                'decision' => 'Move after pickup',
                'reason' => $reason
            ]);
            $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
        } elseif (!empty($placeTileOptions)) {
            $actions[] = $this->createAction('ai_reasoning', [
                'decision' => 'Place tile after pickup',
                'reason' => 'Continuing exploration after item pickup'
            ]);
            $this->executeTilePlacementSequence($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions);
        } elseif (!empty($moveToOptions)) {
            $actions[] = $this->createAction('ai_reasoning', [
                'decision' => 'Move to explore after pickup',
                'reason' => 'No tiles to place, exploring existing areas'
            ]);
            $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
        } else {
            $actions[] = $this->createAction('ai_info', ['message' => 'No more actions available after pickup']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
        }
    }
    
    /**
     * Get monster reward type
     */
    private function getMonsterReward($item): ?string
    {
        if ($item instanceof \App\Game\Item\Item) {
            // Map monster names to their rewards
            $monsterRewards = [
                'giant_rat' => 'dagger',
                'skeleton_warrior' => 'sword', 
                'skeleton_king' => 'axe',
                'skeleton_turnkey' => 'key',
                'mummy' => 'fireball',
                'giant_spider' => 'teleport',
            ];
            
            $monsterName = $item->name->value ?? '';
            return $monsterRewards[$monsterName] ?? null;
        } elseif (is_array($item)) {
            // Check reward in array format
            if (isset($item['reward']['type'])) {
                return $item['reward']['type'];
            }
            // Try to infer from monster type
            if (isset($item['monster']['type'])) {
                $monsterRewards = [
                    'giant_rat' => 'dagger',
                    'skeleton_warrior' => 'sword',
                    'skeleton_king' => 'axe',
                ];
                return $monsterRewards[$item['monster']['type']] ?? null;
            }
        }
        return null;
    }
    
    /**
     * Execute movement towards a target (chest or weapon)
     */
    private function executeMoveTowardsTarget(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, $currentPosition, array $moveToOptions, array $targetPositions, string $targetType, array &$actions): void
    {
        $trackingKey = "{$gameId}_{$playerId}";
        error_log("DEBUG AI: executeMoveTowardsTarget - moving towards {$targetType}");
        error_log("DEBUG AI: Target positions: " . json_encode($targetPositions));
        error_log("DEBUG AI: Available move options: " . json_encode($moveToOptions));
        
        // Verify that moveToOptions actually contains valid positions with tiles
        $field = $this->messageBus->dispatch(new GetField($gameId));
        $placedTiles = $field->getPlacedTiles();
        
        $validMoveOptions = [];
        foreach ($moveToOptions as $moveOption) {
            if (isset($placedTiles[$moveOption])) {
                $validMoveOptions[] = $moveOption;
            } else {
                error_log("DEBUG AI: WARNING - {$moveOption} has no tile! Excluding from valid moves");
            }
        }
        
        if (empty($validMoveOptions)) {
            error_log("DEBUG AI: No valid move options with tiles!");
            // Try placing a tile instead
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            $placeTileOptions = $availablePlaces['placeTile'] ?? [];
            
            if (!empty($placeTileOptions)) {
                $actions[] = $this->createAction('ai_reasoning', ['message' => "No valid moves, placing tile towards {$targetType}"]);
                $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, 0);
            } else {
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            }
            return;
        }
        
        // Enhanced pathfinding: Consider multiple objectives along the path
        $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
        
        // Get all valuable targets on the field (monsters with good loot)
        $monstersOnField = $this->getMonstersOnField($field, $player);
        $valuableMonsters = [];
        foreach ($monstersOnField as $monster) {
            if ($this->isItemWorthAttackingFor($monster['type'], $player)) {
                $valuableMonsters[$monster['position']] = $monster;
            }
        }
        
        // Find the valid move option that optimizes multiple objectives
        $bestMove = null;
        $bestScore = -PHP_INT_MAX;
        $bestReason = '';
        
        [$currentX, $currentY] = explode(',', $currentPosition->toString());
        $currentX = (int)$currentX;
        $currentY = (int)$currentY;
        
        foreach ($validMoveOptions as $moveOption) {
            // Skip positions we've already visited in this turn to prevent oscillation
            if (isset($this->visitedPositions[$moveOption])) {
                continue;
            }
            
            [$moveX, $moveY] = explode(',', $moveOption);
            $moveX = (int)$moveX;
            $moveY = (int)$moveY;
            
            // Calculate score for this move based on multiple factors
            $moveScore = 0;
            $moveReasons = [];
            
            // Factor 1: Distance to primary target (chest)
            $minDistanceToTarget = PHP_INT_MAX;
            $closestTargetForMove = null;
            foreach ($targetPositions as $targetPos) {
                // Handle both array format (chests) and key-value format (weapons)
                if (is_string($targetPos)) {
                    $targetPosition = $targetPos;
                } else {
                    continue; // Skip if not a string position
                }
                
                [$targetX, $targetY] = explode(',', $targetPosition);
                $targetX = (int)$targetX;
                $targetY = (int)$targetY;
                $distance = abs($moveX - $targetX) + abs($moveY - $targetY);
                if ($distance < $minDistanceToTarget) {
                    $minDistanceToTarget = $distance;
                    $closestTargetForMove = $targetPosition;
                }
            }
            // Primary target gets highest weight (negative because lower distance is better)
            if ($minDistanceToTarget < PHP_INT_MAX) {
                $moveScore -= $minDistanceToTarget * 50; // Reduced from 100 to allow more monster influence
                $moveReasons[] = "{$targetType}_dist:{$minDistanceToTarget}";
            }
            
            // Factor 2: Check if there's a valuable monster at this position we can defeat
            if (isset($valuableMonsters[$moveOption])) {
                $monster = $valuableMonsters[$moveOption];
                $playerStrength = $this->calculateEffectiveStrength($player);
                $monsterHP = $monster['hp'] ?? 0;
                
                if ($playerStrength >= $monsterHP) {
                    // Big bonus for defeating a monster along the way
                    $moveScore += 500;
                    $moveReasons[] = "defeats_monster:{$monster['name']}";
                    error_log("DEBUG AI: Move to {$moveOption} would defeat {$monster['name']} for {$monster['type']}!");
                }
            }
            
            // Factor 3: Progress towards valuable monsters (secondary objectives)
            foreach ($valuableMonsters as $monsterPos => $monster) {
                if ($monsterPos === $moveOption) continue; // Already handled above
                
                [$monsterX, $monsterY] = explode(',', $monsterPos);
                $monsterX = (int)$monsterX;
                $monsterY = (int)$monsterY;
                
                $currentDistToMonster = abs($currentX - $monsterX) + abs($currentY - $monsterY);
                $newDistToMonster = abs($moveX - $monsterX) + abs($moveY - $monsterY);
                
                // Bonus if we're getting closer to a valuable monster
                if ($newDistToMonster < $currentDistToMonster) {
                    $improvement = $currentDistToMonster - $newDistToMonster;
                    // Check if monster is roughly on the way to our primary target
                    $monsterDistToTarget = PHP_INT_MAX;
                    if ($closestTargetForMove) {
                        [$targetX, $targetY] = explode(',', $closestTargetForMove);
                        $monsterDistToTarget = abs($monsterX - (int)$targetX) + abs($monsterY - (int)$targetY);
                    }
                    
                    // Higher weight if monster is on the way (within 3 tiles of path to target)
                    if ($monsterDistToTarget <= 3) {
                        $moveScore += $improvement * 40; // Higher weight for monsters on the path
                        $moveReasons[] = "monster_on_path:{$monster['name']}:{$improvement}";
                    } else {
                        $moveScore += $improvement * 20; // Normal weight for monsters off the path
                        $moveReasons[] = "closer_to_{$monster['name']}:{$improvement}";
                    }
                }
            }
            
            // Check if we can defeat the monster at this position (legacy check)
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
            if (!$this->canDefeatMonsterAt($moveOption, $player)) {
                error_log("DEBUG AI: Skipping {$moveOption} - cannot defeat monster there");
                continue;
            }
            
            error_log("DEBUG AI: Move option {$moveOption} score: {$moveScore} (" . implode(', ', $moveReasons) . ")");
            
            if ($moveScore > $bestScore) {
                $bestScore = $moveScore;
                $bestMove = $moveOption;
                $bestReason = implode(', ', $moveReasons);
                $closestTarget = $closestTargetForMove;
            }
        }
        
        if ($bestMove) {
            error_log("DEBUG AI: Best move is {$bestMove} with score {$bestScore} (reasons: {$bestReason})");
            
            // Track progress towards this target
            if ($closestTarget) {
                // Calculate actual distance to the closest target
                [$targetX, $targetY] = explode(',', $closestTarget);
                [$bestX, $bestY] = explode(',', $bestMove);
                $distanceToTarget = abs((int)$bestX - (int)$targetX) + abs((int)$bestY - (int)$targetY);
                
                if (!isset(self::$turnsSinceLastProgress[$trackingKey][$closestTarget])) {
                    self::$turnsSinceLastProgress[$trackingKey][$closestTarget] = ['distance' => $distanceToTarget, 'turns' => 0];
                } else {
                    $previousDistance = self::$turnsSinceLastProgress[$trackingKey][$closestTarget]['distance'];
                    if ($distanceToTarget >= $previousDistance) {
                        // Not making progress
                        self::$turnsSinceLastProgress[$trackingKey][$closestTarget]['turns']++;
                        error_log("DEBUG AI: No progress towards {$closestTarget} for " . self::$turnsSinceLastProgress[$trackingKey][$closestTarget]['turns'] . " turns");
                        
                        // If we haven't made progress for 2+ turns, mark as unreachable
                        if (self::$turnsSinceLastProgress[$trackingKey][$closestTarget]['turns'] >= 2) {
                            self::$unreachableTargets[$trackingKey][$closestTarget] = true;
                            error_log("DEBUG AI: Marking {$closestTarget} as UNREACHABLE!");
                            
                            $actions[] = $this->createAction('ai_reasoning', [
                                'message' => "Target at {$closestTarget} appears unreachable, abandoning pursuit"
                            ]);
                            
                            // Try placing a tile instead
                            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                                gameId: $gameId,
                                playerId: $playerId,
                                messageBus: $this->messageBus,
                            ));
                            $placeTileOptions = $availablePlaces['placeTile'] ?? [];
                            
                            if (!empty($placeTileOptions)) {
                                $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, 0);
                            } else {
                                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                            }
                            return;
                        }
                    } else {
                        // We're making progress, reset counter
                        self::$turnsSinceLastProgress[$trackingKey][$closestTarget] = ['distance' => $distanceToTarget, 'turns' => 0];
                    }
                }
            }
            
            // Move towards the target
            [$fromX, $fromY] = explode(',', $currentPosition->toString());
            [$toX, $toY] = explode(',', $bestMove);
            
            // Check move limit
            if ($this->moveCount >= self::MAX_MOVES_PER_TURN) {
                $actions[] = $this->createAction('ai_info', ['message' => 'Reached move limit for turn, ending turn']);
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                return;
            }
            
            $actions[] = $this->createAction('ai_reasoning', [
                'message' => "Moving from {$currentPosition->toString()} to {$bestMove} towards {$targetType} at {$closestTarget}"
            ]);
            
            $this->moveCount++;
            $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
            $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
            
            if ($moveResult['success']) {
                // Mark position as visited to prevent oscillation
                $this->visitedPositions[$bestMove] = true;
                $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
            } else {
                error_log("DEBUG AI: Move failed! " . json_encode($moveResult));
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            }
        } else {
            // Check if all positions have been visited
            $unvisitedOptions = array_filter($validMoveOptions, fn($option) => !isset($this->visitedPositions[$option]));
            
            if (empty($unvisitedOptions)) {
                error_log("DEBUG AI: All reachable positions have been visited, target is unreachable");
                $actions[] = $this->createAction('ai_reasoning', [
                    'message' => "Target {$targetType} is unreachable, ending turn"
                ]);
                
                // Try placing a tile to open new paths
                $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                    gameId: $gameId,
                    playerId: $playerId,
                    messageBus: $this->messageBus,
                ));
                $placeTileOptions = $availablePlaces['placeTile'] ?? [];
                
                if (!empty($placeTileOptions)) {
                    $actions[] = $this->createAction('ai_reasoning', ['message' => "Placing tile to open new paths"]);
                    $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, 0);
                } else {
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                }
            } else {
                // Fallback to normal movement with unvisited options
                error_log("DEBUG AI: No best move found, using normal movement with unvisited options");
                $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $unvisitedOptions, $actions);
            }
        }
    }
    
    /**
     * Calculate the probability of defeating a monster
     * @return float Probability from 0.0 to 1.0
     */
    private function calculateDefeatProbability($monsterHP, $player): float
    {
        if ($monsterHP <= 0) {
            return 1.0; // Already defeated
        }
        
        // Get base weapon damage
        $weaponDamage = 0;
        foreach ($player->getAllItems() as $playerItem) {
            if ($playerItem->type->getCategory() === \App\Game\Item\ItemCategory::WEAPON) {
                $weaponDamage += $playerItem->type->getDamage();
            }
        }
        
        // Check for consumables
        $consumableDamage = 0;
        foreach ($player->getAllItems() as $playerItem) {
            if ($playerItem->type === \App\Game\Item\ItemType::FIREBALL) {
                $consumableDamage += $playerItem->type->getDamage();
                break; // Only count one for probability calc
            }
        }
        
        // 2d6 probabilities: roll -> ways to get it / 36
        // 2: 1/36, 3: 2/36, 4: 3/36, 5: 4/36, 6: 5/36, 7: 6/36,
        // 8: 5/36, 9: 4/36, 10: 3/36, 11: 2/36, 12: 1/36
        $diceProbs = [
            2 => 1/36, 3 => 2/36, 4 => 3/36, 5 => 4/36, 6 => 5/36, 7 => 6/36,
            8 => 5/36, 9 => 4/36, 10 => 3/36, 11 => 2/36, 12 => 1/36
        ];
        
        // Calculate win probability
        $winProbability = 0.0;
        
        // Calculate minimum dice roll needed to win (must exceed monster HP)
        $minDiceNeeded = $monsterHP - $weaponDamage + 1;  // Need to deal MORE than monster HP
        $minDiceWithConsumable = $monsterHP - $weaponDamage - $consumableDamage + 1;
        
        // Sum probabilities for all winning dice rolls
        foreach ($diceProbs as $roll => $prob) {
            if ($roll >= $minDiceNeeded) {
                // Can win without consumable
                $winProbability += $prob;
            } else if ($consumableDamage > 0 && $roll >= $minDiceWithConsumable) {
                // Can win only with consumable - use it if aggressive
                $riskTolerance = $this->strategyConfig['riskTolerance'] ?? 0.5;
                if ($riskTolerance >= 0.7) {
                    // Aggressive: always use consumables to win
                    $winProbability += $prob;
                } else if ($riskTolerance >= 0.4) {
                    // Balanced: use consumable 50% of the time in this situation
                    $winProbability += $prob * 0.5;
                } 
                // Defensive: save consumables, don't count this as winnable
            }
        }
        
        return min(1.0, $winProbability); // Cap at 1.0 in case calculations exceed it
    }
    
    /**
     * Check if player should attempt to defeat a monster at the given position
     * based on strategy and win probability
     */
    private function canDefeatMonsterAt(string $position, $player): bool
    {
        $field = $this->messageBus->dispatch(new GetField($player->getGameId()));
        $items = $field->getItems();
        
        if (!isset($items[$position])) {
            return true; // No item means no monster
        }
        
        $item = $items[$position];
        $monsterHP = $this->getMonsterHP($item);
        
        if ($monsterHP <= 0) {
            return true; // Monster already defeated or no monster
        }
        
        // Calculate win probability
        $winProbability = $this->calculateDefeatProbability($monsterHP, $player);
        
        // Get monster name for better logging
        $monsterName = 'unknown';
        if ($item instanceof \App\Game\Item\Item) {
            $monsterName = $item->name->value ?? 'unknown';
        }
        
        // If win probability is 0%, always skip
        if ($winProbability <= 0.0) {
            error_log("DEBUG AI: Cannot defeat {$monsterName} at {$position}: HP={$monsterHP}, WinChance=0%");
            return false;
        }
        
        // Get minimum acceptable probability based on strategy
        $minAcceptableProbability = $this->getMinAcceptableBattleProbability();
        
        $shouldAttempt = $winProbability >= $minAcceptableProbability;
        
        if (!$shouldAttempt) {
            $winPercent = round($winProbability * 100);
            $minPercent = round($minAcceptableProbability * 100);
            error_log("DEBUG AI: Avoiding {$monsterName} at {$position}: HP={$monsterHP}, WinChance={$winPercent}% < Required={$minPercent}%");
        } else {
            $winPercent = round($winProbability * 100);
            error_log("DEBUG AI: Can attempt {$monsterName} at {$position}: HP={$monsterHP}, WinChance={$winPercent}%");
        }
        
        return $shouldAttempt;
    }
    
    /**
     * Get minimum acceptable battle probability based on current strategy
     */
    private function getMinAcceptableBattleProbability(): float
    {
        // Risk tolerance from strategy config (0.0 to 1.0)
        $riskTolerance = $this->strategyConfig['riskTolerance'] ?? 0.5;
        
        // Aggressive strategy: Accept lower win probabilities
        if ($riskTolerance >= 0.7) {
            return 0.3; // Accept 30% or better chance
        }
        // Balanced strategy: Moderate risk
        else if ($riskTolerance >= 0.4) {
            return 0.5; // Accept 50% or better chance
        }
        // Defensive strategy: Only take safe battles
        else {
            return 0.7; // Require 70% or better chance
        }
    }
    
    /**
     * Execute movement towards better weapons on the field
     */
    private function executeMoveTowardsBetterWeapon(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, $currentPosition, array $moveToOptions, array $betterWeaponsOnField, array &$actions): void
    {
        $trackingKey = "{$gameId}_{$playerId}";
        
        // If we have a persistent target weapon from a previous turn, check if it's still available
        if (self::$persistentTargets[$trackingKey] !== null) {
            if (isset($betterWeaponsOnField[self::$persistentTargets[$trackingKey]])) {
                error_log("DEBUG AI: Continuing pursuit of " . self::$persistentTargetReasons[$trackingKey] . " at " . self::$persistentTargets[$trackingKey] . " from previous turn");
                $reachableWeapons = [self::$persistentTargets[$trackingKey] => $betterWeaponsOnField[self::$persistentTargets[$trackingKey]]];
            } else {
                // Target no longer available (picked up by someone else or disappeared)
                error_log("DEBUG AI: Previous target at " . self::$persistentTargets[$trackingKey] . " is no longer available, clearing and finding new target");
                self::$persistentTargets[$trackingKey] = null;
                self::$persistentTargetReasons[$trackingKey] = null;
                
                // Filter out unreachable targets for new selection
                $reachableWeapons = array_filter($betterWeaponsOnField, function($pos) use ($trackingKey) {
                    return !isset(self::$unreachableTargets[$trackingKey][$pos]);
                }, ARRAY_FILTER_USE_KEY);
            }
        } else {
            // No persistent target, filter out unreachable targets
            $reachableWeapons = array_filter($betterWeaponsOnField, function($pos) use ($trackingKey) {
                return !isset(self::$unreachableTargets[$trackingKey][$pos]);
            }, ARRAY_FILTER_USE_KEY);
        }
        
        if (empty($reachableWeapons)) {
            error_log("DEBUG AI: All weapon targets are unreachable");
            $actions[] = $this->createAction('ai_reasoning', ['message' => 'All weapon targets are unreachable, exploring instead']);
            $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
            return;
        }
        
        error_log("DEBUG AI: executeMoveTowardsBetterWeapon - current position: {$currentPosition->toString()}");
        error_log("DEBUG AI: Available move options: " . json_encode($moveToOptions));
        error_log("DEBUG AI: Better weapons on field (reachable): " . json_encode($reachableWeapons));
        
        // Verify that moveToOptions actually contains valid positions with tiles
        $field = $this->messageBus->dispatch(new GetField($gameId));
        $placedTiles = $field->getPlacedTiles();
        
        $validMoveOptions = [];
        foreach ($moveToOptions as $moveOption) {
            if (isset($placedTiles[$moveOption])) {
                $validMoveOptions[] = $moveOption;
                error_log("DEBUG AI: {$moveOption} is valid (has tile)");
            } else {
                error_log("DEBUG AI: WARNING - {$moveOption} has no tile! Excluding from valid moves");
            }
        }
        
        if (empty($validMoveOptions)) {
            error_log("DEBUG AI: No valid move options with tiles!");
            // Try placing a tile instead
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            $placeTileOptions = $availablePlaces['placeTile'] ?? [];
            
            if (!empty($placeTileOptions)) {
                $actions[] = $this->createAction('ai_reasoning', ['message' => 'No valid moves, placing tile towards better weapons']);
                $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, 0);
            } else {
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            }
            return;
        }
        
        // Check if we have a persistent path to follow
        $currentPosStr = $currentPosition->toString();
        
        if (isset(self::$persistentPaths[$trackingKey]) && !empty(self::$persistentPaths[$trackingKey])) {
            $path = self::$persistentPaths[$trackingKey];
            $actions[] = $this->createAction('persistent_path_check', [
                'has_path' => true,
                'path' => $path,
                'current_position' => $currentPosStr
            ]);
            
            // Remove positions we've already reached
            while (!empty($path) && $path[0] === $currentPosStr) {
                array_shift($path);
                self::$persistentPaths[$trackingKey] = $path;
            }
            
            // Check if next step in path is available
            if (!empty($path) && in_array($path[0], $validMoveOptions)) {
                $bestMove = $path[0];
                $targetWeapon = self::$persistentTargetReasons[$trackingKey] ?? "weapon";
                error_log("DEBUG AI: Following persistent path, next step: {$bestMove}");
            } else {
                // Path is no longer valid, clear it
                error_log("DEBUG AI: Persistent path no longer valid, recalculating");
                self::$persistentPaths[$trackingKey] = [];
            }
        }
        
        // If no valid path, calculate a new one
        if (!isset($bestMove)) {
            // Find the best weapon target and calculate a complete path to it
            $bestTarget = null;
            $bestPath = [];
            $shortestPathLength = PHP_INT_MAX;
            
            foreach ($reachableWeapons as $weaponPos => $weaponType) {
                // Calculate full path to this weapon
                $actions[] = $this->createAction('pathfinding_attempt', [
                    'from' => $currentPosStr,
                    'to' => $weaponPos,
                    'weapon' => $weaponType
                ]);
                
                $path = $this->findPathToTarget($currentPosStr, $weaponPos, $placedTiles, $field, $actions);
                
                if (!empty($path)) {
                    $actions[] = $this->createAction('pathfinding_result', [
                        'success' => true,
                        'path_length' => count($path),
                        'path_preview' => implode(' -> ', array_slice($path, 0, 5)) . (count($path) > 5 ? '...' : '')
                    ]);
                    
                    if (count($path) < $shortestPathLength) {
                        $shortestPathLength = count($path);
                        $bestPath = $path;
                        $bestTarget = $weaponPos;
                        $targetWeapon = "{$weaponType} at {$weaponPos}";
                    }
                } else {
                    $actions[] = $this->createAction('pathfinding_result', [
                        'success' => false,
                        'reason' => 'No valid path found'
                    ]);
                }
            }
            
            if (!empty($bestPath) && count($bestPath) > 1) {
                // Store the complete path (excluding current position)
                array_shift($bestPath); // Remove current position
                self::$persistentPaths[$trackingKey] = $bestPath;
                self::$persistentTargets[$trackingKey] = $bestTarget;
                self::$persistentTargetReasons[$trackingKey] = $targetWeapon;
                
                error_log("DEBUG AI: Calculated new path to {$targetWeapon}: " . json_encode($bestPath));
                
                // Take the first step if it's available
                if (in_array($bestPath[0], $validMoveOptions)) {
                    $bestMove = $bestPath[0];
                }
            }
        }
        
        // If still no valid path through placed tiles, use Manhattan distance to move in general direction
        // The AI will place tiles when needed to continue progress
        if (!isset($bestMove)) {
            error_log("DEBUG AI: No path through placed tiles, using Manhattan distance to move towards target");
            error_log("DEBUG AI: Will place tiles as needed to create path");
            
            // Keep the same target we had before if it exists
            if (isset(self::$persistentTargets[$trackingKey])) {
                $targetPos = self::$persistentTargets[$trackingKey];
                $targetWeapon = self::$persistentTargetReasons[$trackingKey];
                error_log("DEBUG AI: Keeping persistent target: {$targetWeapon}");
            }
            
            $bestMove = null;
            $shortestDistance = PHP_INT_MAX;
            
            [$currentX, $currentY] = explode(',', $currentPosition->toString());
            $currentX = (int)$currentX;
            $currentY = (int)$currentY;
        
        foreach ($validMoveOptions as $moveOption) {
            // Only skip visited positions if we have unvisited alternatives
            // This allows backtracking when necessary to reach distant goals
            $unvisitedOptions = array_filter($validMoveOptions, fn($opt) => !isset($this->visitedPositions[$opt]));
            if (isset($this->visitedPositions[$moveOption]) && !empty($unvisitedOptions)) {
                error_log("DEBUG AI: Skipping {$moveOption} - already visited this turn and have unvisited alternatives");
                continue;
            }
            
            // Check if we can defeat the monster at this position
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
            if (!$this->canDefeatMonsterAt($moveOption, $player)) {
                error_log("DEBUG AI: Skipping {$moveOption} - cannot defeat monster there");
                continue;
            }
            
            [$moveX, $moveY] = explode(',', $moveOption);
            $moveX = (int)$moveX;
            $moveY = (int)$moveY;
            
            // If we have a persistent target, only calculate distance to that
            // Otherwise, find the closest weapon
            if (isset(self::$persistentTargets[$trackingKey]) && isset($reachableWeapons[self::$persistentTargets[$trackingKey]])) {
                $weaponPos = self::$persistentTargets[$trackingKey];
                $weaponType = $reachableWeapons[$weaponPos];
                [$weaponX, $weaponY] = explode(',', $weaponPos);
                $weaponX = (int)$weaponX;
                $weaponY = (int)$weaponY;
                
                // Manhattan distance from move option to our persistent target
                $distance = abs($moveX - $weaponX) + abs($moveY - $weaponY);
                
                if ($distance < $shortestDistance) {
                    $shortestDistance = $distance;
                    $bestMove = $moveOption;
                    $targetWeapon = "{$weaponType} at {$weaponPos}";
                }
            } else {
                // No persistent target, find closest weapon
                foreach ($reachableWeapons as $weaponPos => $weaponType) {
                    [$weaponX, $weaponY] = explode(',', $weaponPos);
                    $weaponX = (int)$weaponX;
                    $weaponY = (int)$weaponY;
                    
                    // Manhattan distance from move option to weapon
                    $distance = abs($moveX - $weaponX) + abs($moveY - $weaponY);
                    
                    if ($distance < $shortestDistance) {
                        $shortestDistance = $distance;
                        $bestMove = $moveOption;
                        $targetWeapon = "{$weaponType} at {$weaponPos}";
                        $targetPos = $weaponPos; // Set this for persistence
                    }
                }
            }
        }
        }  // End of Manhattan distance fallback
        
        if ($bestMove) {
            // Extract just the position from targetWeapon for tracking (e.g., "2,1" from "sword at 2,1")
            $targetPos = null;
            foreach ($reachableWeapons as $pos => $type) {
                if ($targetWeapon === "{$type} at {$pos}") {
                    $targetPos = $pos;
                    break;
                }
            }
            
            // Get target position from persistent data or extract from targetWeapon
            if (!isset($targetPos)) {
                $targetPos = self::$persistentTargets[$trackingKey] ?? null;
                if (!$targetPos) {
                    foreach ($reachableWeapons as $pos => $type) {
                        if (isset($targetWeapon) && $targetWeapon === "{$type} at {$pos}") {
                            $targetPos = $pos;
                            break;
                        }
                    }
                }
            }
            
            $pathLength = isset(self::$persistentPaths[$trackingKey]) ? count(self::$persistentPaths[$trackingKey]) : 0;
            error_log("DEBUG AI: Best move is {$bestMove} towards {$targetWeapon} (path length: {$pathLength})");
            
            // Ensure persistent data is set
            if ($targetPos && !isset(self::$persistentTargets[$trackingKey])) {
                self::$persistentTargets[$trackingKey] = $targetPos;
                self::$persistentTargetReasons[$trackingKey] = $targetWeapon;
                error_log("DEBUG AI: Set persistent target to {$targetWeapon} at {$targetPos} for multi-turn pursuit");
            }
            
            // Track progress towards this weapon position
            [$currentX, $currentY] = explode(',', $currentPosStr);
            $currentX = (int)$currentX;
            $currentY = (int)$currentY;
            
            if ($targetPos) {
                [$targetX, $targetY] = explode(',', $targetPos);
                $actualDistanceToTarget = abs($currentX - (int)$targetX) + abs($currentY - (int)$targetY);
            } else {
                $actualDistanceToTarget = 0;
            }
            
            if (!isset(self::$turnsSinceLastProgress[$trackingKey][$targetPos])) {
                self::$turnsSinceLastProgress[$trackingKey][$targetPos] = ['distance' => $actualDistanceToTarget, 'turns' => 0, 'last_positions' => []];
            } else {
                $previousDistance = self::$turnsSinceLastProgress[$trackingKey][$targetPos]['distance'];
                $lastPositions = self::$turnsSinceLastProgress[$trackingKey][$targetPos]['last_positions'] ?? [];
                
                // Check if we're revisiting the same position we were at in a previous turn (oscillating)
                if (in_array($currentPosition->toString(), $lastPositions)) {
                    self::$turnsSinceLastProgress[$trackingKey][$targetPos]['turns'] += 2; // Penalize oscillation more heavily
                    error_log("DEBUG AI: OSCILLATION DETECTED! Revisiting {$currentPosition->toString()} while pursuing {$targetPos}");
                } elseif ($actualDistanceToTarget >= $previousDistance) {
                    // Not making progress
                    self::$turnsSinceLastProgress[$trackingKey][$targetPos]['turns']++;
                    error_log("DEBUG AI: No progress towards weapon at {$targetPos} for " . self::$turnsSinceLastProgress[$trackingKey][$targetPos]['turns'] . " turns (distance: {$actualDistanceToTarget} vs previous: {$previousDistance})");
                } else {
                    // We're making progress, reset counter but keep position history
                    self::$turnsSinceLastProgress[$trackingKey][$targetPos]['turns'] = 0;
                    self::$turnsSinceLastProgress[$trackingKey][$targetPos]['distance'] = $actualDistanceToTarget;
                    error_log("DEBUG AI: Making progress towards {$targetPos} (distance: {$actualDistanceToTarget} vs previous: {$previousDistance})");
                }
                
                // Keep track of last 3 positions to detect oscillation
                $lastPositions[] = $currentPosition->toString();
                if (count($lastPositions) > 3) {
                    array_shift($lastPositions);
                }
                self::$turnsSinceLastProgress[$trackingKey][$targetPos]['last_positions'] = $lastPositions;
                
                // If we're oscillating or haven't made progress for 5+ turns, consider changing strategy (but not marking as unreachable)
                if (self::$turnsSinceLastProgress[$trackingKey][$targetPos]['turns'] >= 5) {
                    error_log("DEBUG AI: No progress towards {$targetPos} for 5+ turns, considering alternative strategies");
                    
                    // Don't mark as unreachable! Just try a different approach
                    // Keep the persistent target but try placing tiles to open new paths
                    $actions[] = $this->createAction('ai_reasoning', [
                        'message' => "No direct path to {$targetWeapon}, will continue moving in general direction and place tiles when possible"
                    ]);
                    
                    // Try placing a tile to open new paths instead of backtracking
                    $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                        gameId: $gameId,
                        playerId: $playerId,
                        messageBus: $this->messageBus,
                    ));
                    $placeTileOptions = $availablePlaces['placeTile'] ?? [];
                    
                    if (!empty($placeTileOptions)) {
                        $actions[] = $this->createAction('ai_decision', [
                            'decision' => 'Placing tile to open new paths',
                            'reason' => 'Current target unreachable, trying to create new routes'
                        ]);
                        $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, 0);
                        return;
                    }
                    // No tiles to place, but still continue moving in the general direction
                    // Don't return here - let the normal movement logic continue
                }
            }
            
            // Move towards the better weapon
            [$fromX, $fromY] = explode(',', $currentPosition->toString());
            [$toX, $toY] = explode(',', $bestMove);
            
            // Check move limit
            if ($this->moveCount >= self::MAX_MOVES_PER_TURN) {
                $actions[] = $this->createAction('ai_info', ['message' => 'Reached move limit for turn, ending turn']);
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                return;
            }
            
            $actions[] = $this->createAction('ai_reasoning', [
                'message' => "Moving from {$currentPosition->toString()} to {$bestMove} towards {$targetWeapon}"
            ]);
            
            $this->moveCount++;
            $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
            $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
            
            if ($moveResult['success']) {
                // Mark position as visited to prevent oscillation
                $this->visitedPositions[$bestMove] = true;
                $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
            } else {
                error_log("DEBUG AI: Move failed! " . json_encode($moveResult));
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            }
        } else {
            // Fallback to normal movement
            error_log("DEBUG AI: No best move found, using normal movement");
            $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
        }
    }
    
    /**
     * Execute movement action
     */
    private function executeMovement(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, $currentPosition, array $moveToOptions, array &$actions): void
    {
        // Check action limit to prevent infinite loops
        if (count($actions) > self::MAX_ACTIONS_PER_TURN) {
            $actions[] = $this->createAction('ai_info', ['message' => 'Action limit reached, ending turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Get field and player for strategy decisions
        $field = $this->messageBus->dispatch(new GetField($gameId));
        $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
        
        // Validate move options have tiles
        $placedTiles = $field->getPlacedTiles();
        $validMoveOptions = array_filter($moveToOptions, function($option) use ($placedTiles) {
            return isset($placedTiles[$option]);
        });
        
        if (empty($validMoveOptions)) {
            error_log("DEBUG AI: No valid move options with tiles in executeMovement!");
            $actions[] = $this->createAction('ai_info', ['message' => 'No valid moves available (no tiles at positions)']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Filter out positions with unbeatable monsters
        $safeOptions = array_filter($validMoveOptions, function($option) use ($player) {
            return $this->canDefeatMonsterAt($option, $player);
        });
        
        if (empty($safeOptions)) {
            error_log("DEBUG AI: All move options have unbeatable monsters!");
            $actions[] = $this->createAction('ai_info', ['message' => 'All moves blocked by strong monsters, ending turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Choose move based on strategy (from safe options only)
        $targetPosition = $this->chooseMovementTarget($safeOptions, $field, $player, $gameId, $playerId);
        
        // If no valid move target (all positions visited), end exploration
        if ($targetPosition === null) {
            error_log("DEBUG AI: No unvisited positions available - ending movement phase");
            $actions[] = $this->createAction('ai_info', ['message' => 'All reachable positions explored, no further movement possible']);
            
            // Check if we can place tiles to open new areas
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            $placeTileOptions = $availablePlaces['placeTile'] ?? [];
            
            if (!empty($placeTileOptions)) {
                $actions[] = $this->createAction('ai_reasoning', ['message' => 'Placing tile to open new areas for exploration']);
                $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, 0);
            } else {
                $actions[] = $this->createAction('ai_info', ['message' => 'No tiles to place and all positions explored, ending turn']);
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            }
            return;
        }
        
        [$fromX, $fromY] = explode(',', $currentPosition->toString());
        [$toX, $toY] = explode(',', $targetPosition);
        
        // Check move limit
        if ($this->moveCount >= self::MAX_MOVES_PER_TURN) {
            $actions[] = $this->createAction('ai_info', ['message' => 'Reached move limit for turn, ending turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        $actions[] = $this->createAction('ai_decision', [
            'decision' => 'Moving to position',
            'target' => $targetPosition,
            'reason' => $this->getSpecificMoveReason($targetPosition, $field, $player)
        ]);
        
        $this->moveCount++;
        $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
        $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
        
        if ($moveResult['success']) {
            // Mark the new position as visited to prevent oscillation within turn
            $this->visitedPositions[$targetPosition] = true;
            
            // Also mark in exploration history to track across turns
            $trackingKey = "{$gameId}_{$playerId}";
            self::$explorationHistory[$trackingKey][$targetPosition] = true;
            
            $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
        } else {
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
        }
    }
    
    /**
     * Execute tile placement sequence (may require multiple tiles for corridors)
     */
    private function executeTilePlacementSequence(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, $currentPosition, array $placeTileOptions, array &$actions): void
    {
        $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, 0);
    }
    
    /**
     * Execute tile placement with proper battle and pickup handling
     * Mandatory: pick tile, place tile, move player
     * Optional: if battleInfo exists, handle battle result
     * @param int $sequenceDepth Track how many tiles placed in this sequence (for corridors)
     */
    private function executeTilePlacement(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, $currentPosition, array $placeTileOptions, array &$actions, int $sequenceDepth = 0): void
    {
        // Check if deck is empty before attempting to place tile
        $deck = $this->messageBus->dispatch(new GetDeck($gameId));
        if ($deck->isEmpty()) {
            $actions[] = $this->createAction('ai_info', ['message' => 'Deck is empty, cannot place tiles']);
            // Try to move instead if possible
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            $moveToOptions = $availablePlaces['moveTo'] ?? [];
            
            if (!empty($moveToOptions)) {
                $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
            } else {
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            }
            return;
        }
        
        // Check action limit to prevent infinite loops
        if (count($actions) > self::MAX_ACTIONS_PER_TURN) {
            $actions[] = $this->createAction('ai_info', ['message' => 'Action limit reached, ending turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Check sequence depth to prevent too many consecutive tile placements
        if ($sequenceDepth >= self::MAX_TILES_PER_SEQUENCE) {
            $actions[] = $this->createAction('ai_info', ['message' => 'Max tiles per sequence reached, ending turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Get field and player for strategy decisions
        $field = $this->messageBus->dispatch(new GetField($gameId));
        $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
        
        // Choose tile placement based on strategy
        $chosenPlace = $this->chooseTilePlacement($placeTileOptions, $field, $player);
        [$x, $y] = explode(',', $chosenPlace);
        [$currentX, $currentY] = explode(',', $currentPosition->toString());
        
        $actions[] = $this->createAction('ai_decision', [
            'decision' => 'Placing tile',
            'target' => $chosenPlace,
            'reason' => $this->getTilePlacementReason($chosenPlace, $placeTileOptions, $field, $player)
        ]);
        
        $requiredOpenSide = $this->determineRequiredOpenSide((int)$currentX, (int)$currentY, (int)$x, (int)$y);
        $tileId = \App\Infrastructure\Uuid\Uuid::v7();
        
        // MANDATORY Step 1: Pick tile
        $pickResult = $this->apiClient->pickTile($gameId, $tileId, $playerId, $currentTurnId, $requiredOpenSide, (int)$x, (int)$y);
        $actions[] = $this->createAction('pick_tile', ['result' => $pickResult]);
        
        if (!$pickResult['success']) {
            // End turn if can't pick tile
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // MANDATORY Step 2: Place tile
        $placeResult = $this->apiClient->placeTile($gameId, $tileId, $playerId, $currentTurnId, (int)$x, (int)$y);
        $actions[] = $this->createAction('place_tile', ['result' => $placeResult]);
        
        if (!$placeResult['success']) {
            // End turn if can't place tile
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Check if the placed tile is a corridor (non-room) BEFORE moving
        $isRoom = $placeResult['response']['tile']['room'] ?? true;
        
        // MANDATORY Step 3: Move player
        $this->moveCount++;
        $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$currentX, (int)$currentY, (int)$x, (int)$y, true);
        $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
        
        if (!$moveResult['success']) {
            // End turn if can't move
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // If we placed a corridor (non-room), we need to place another tile
        if (!$isRoom) {
            $actions[] = $this->createAction('corridor_detected', ['message' => 'Placed corridor tile, placing another tile']);
            
            // Get new available places from the corridor position
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            $placeTileOptions = $availablePlaces['placeTile'] ?? [];
            
            if (!empty($placeTileOptions)) {
                // Get current position (now at the corridor)
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                // Recursively place another tile (increment sequence depth)
                $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, $sequenceDepth + 1);
                return;
            } else {
                $actions[] = $this->createAction('ai_info', ['message' => 'No more tile placement options after corridor']);
            }
        }
        
        // Before handling move result, explicitly check for chest at new position
        // The API might not report chests in itemInfo for newly placed tiles
        $currentPos = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        $field = $this->messageBus->dispatch(new GetField($gameId));
        $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
        
        if ($this->playerHasKey($player)) {
            error_log('DEBUG AI: Player has key, checking position ' . $currentPos->toString() . ' for chest after tile placement');
            if ($this->positionHasChest($currentPos->toString(), $field)) {
                $actions[] = $this->createAction('chest_detected_after_placement', [
                    'message' => 'Chest found at newly placed tile!',
                    'position' => $currentPos->toString()
                ]);
                
                // Inject chest info into move response if not present
                if (!isset($moveResult['response']['itemInfo']) || $moveResult['response']['itemInfo'] === null) {
                    $moveResult['response']['itemInfo'] = [
                        'type' => 'chest',
                        'locked' => true,
                        'treasure' => true,
                        'detected_by_field_check' => true
                    ];
                    $actions[] = $this->createAction('chest_info_injected', [
                        'message' => 'Added chest info that API did not provide'
                    ]);
                }
            }
        }
        
        // Handle move result (battle, etc.) only for room tiles or when no more tiles to place
        $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
    }
    
    /**
     * Handle the result of a move (battle, tile features, etc.)
     */
    private function handleMoveResult(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array $moveResponse, array &$actions): void
    {
        error_log('DEBUG AI moveResponse: ' . json_encode($moveResponse, JSON_PRETTY_PRINT));
        
        // Check for itemInfo (item without battle)
        if (isset($moveResponse['itemInfo']) && $moveResponse['itemInfo'] !== null) {
            $itemInfo = $moveResponse['itemInfo'];
            $item = $itemInfo['item'] ?? null;
            $itemType = $item['type'] ?? '';
            
            $actions[] = $this->createAction('item_detected', ['type' => $itemType, 'item' => $itemInfo]);
            
            // Check if we should pick up this item
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
            $shouldPickup = false;
            $reason = '';
            
            // ALWAYS pick up treasures - they are the win condition!
            if ($this->isChestOrTreasure($itemInfo)) {
                $shouldPickup = true;
                $reason = 'Found treasure chest - collecting for win condition!';
            }
            // Pick up weapons if they're upgrades
            elseif (in_array($itemType, ['dagger', 'sword', 'axe'])) {
                if ($this->shouldPickupItem($item, $player)) {
                    $shouldPickup = true;
                    $reason = "Found {$itemType} - picking up as weapon upgrade!";
                } else {
                    $reason = "Already have better weapons, skipping {$itemType}";
                }
            }
            // Pick up keys if we don't have one
            elseif ($itemType === 'key') {
                if (!$this->playerHasKey($player)) {
                    $shouldPickup = true;
                    $reason = 'Found key - needed for opening chests!';
                } else {
                    $reason = 'Already have a key, skipping';
                }
            }
            // Pick up spells (if we have room)
            elseif (in_array($itemType, ['fireball', 'teleport'])) {
                $inventory = $player->getInventory();
                $spellCount = isset($inventory['spell']) ? count($inventory['spell']) : 0;
                
                if ($spellCount < 3) { // MAX_SPELLS is 3
                    $shouldPickup = true;
                    $reason = "Found {$itemType} spell - useful for combat/movement!";
                } else {
                    $reason = "Spell inventory full (3/3), skipping {$itemType}";
                    
                    // Track this item as unpickable due to full inventory
                    $trackingKey = "{$gameId}_{$playerId}";
                    $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                    $positionStr = $currentPosition->toString();
                    if (!isset(self::$unPickableItems[$trackingKey])) {
                        self::$unPickableItems[$trackingKey] = [];
                    }
                    self::$unPickableItems[$trackingKey][$positionStr] = true;
                }
            }
            
            if ($shouldPickup) {
                $actions[] = $this->createAction('item_reasoning', [
                    'decision' => 'Pick up item',
                    'reason' => $reason
                ]);
                
                // Pick up the item
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                [$x, $y] = explode(',', $currentPosition->toString());
                $pickupResult = $this->apiClient->pickItem($gameId, $playerId, $currentTurnId, (int)$x, (int)$y);
                $actions[] = $this->createAction('pickup_result', ['result' => $pickupResult]);
                
                // Check if pickup actually succeeded (not just API call success)
                $actuallyPickedUp = $pickupResult['success'] && 
                                   (!isset($pickupResult['response']['inventoryFull']) || 
                                    $pickupResult['response']['inventoryFull'] !== true);
                
                if ($actuallyPickedUp) {
                    // Item was actually picked up, clear persistent target and end turn
                    $trackingKey = "{$gameId}_{$playerId}";
                    $currentPos = "{$x},{$y}";
                    
                    // Clear persistent target if we just picked it up
                    if (isset(self::$persistentTargets[$trackingKey]) && self::$persistentTargets[$trackingKey] === $currentPos) {
                        error_log("DEBUG AI: Successfully picked up persistent target at {$currentPos}, clearing target");
                        self::$persistentTargets[$trackingKey] = null;
                        self::$persistentTargetReasons[$trackingKey] = null;
                    }
                    
                    $actions[] = $this->createAction('ai_info', ['message' => 'Item picked up, ending turn']);
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                } else if (isset($pickupResult['response']['inventoryFull']) && $pickupResult['response']['inventoryFull'] === true) {
                    // Inventory was full, check if we should replace an item
                    $trackingKey = "{$gameId}_{$playerId}";
                    $currentPos = "{$x},{$y}";
                    
                    // For weapons, check if we should replace a weaker weapon
                    if (in_array($itemType, ['dagger', 'sword', 'axe'])) {
                        $inventory = $player->getInventory();
                        $weapons = isset($inventory['weapon']) ? $inventory['weapon'] : [];
                        $weaponPriority = ['dagger' => 1, 'sword' => 2, 'axe' => 3];
                        $newPriority = $weaponPriority[$itemType] ?? 0;
                        
                        $weakestWeapon = null;
                        $weakestPriority = PHP_INT_MAX;
                        
                        foreach ($weapons as $weapon) {
                            // Handle both array and Item object formats
                            if ($weapon instanceof \App\Game\Item\Item) {
                                $weaponType = $weapon->type->value ?? '';
                                $weaponId = $weapon->itemId;
                            } else {
                                $weaponType = $weapon['type'] ?? '';
                                $weaponId = $weapon['itemId'] ?? null;
                            }
                            
                            $invPriority = $weaponPriority[$weaponType] ?? 0;
                            if ($invPriority < $weakestPriority) {
                                $weakestPriority = $invPriority;
                                $weakestWeapon = $weaponId;
                            }
                        }
                        
                        // If new weapon is better than our weakest, replace it
                        if ($newPriority > $weakestPriority && $weakestWeapon) {
                            $actions[] = $this->createAction('ai_reasoning', [
                                'message' => "Replacing weaker weapon with {$itemType}"
                            ]);
                            
                            // Try pickup with replacement
                            $pickupResult = $this->apiClient->pickItem($gameId, $playerId, $currentTurnId, (int)$x, (int)$y, $weakestWeapon);
                            $actions[] = $this->createAction('pickup_with_replace', ['result' => $pickupResult]);
                            
                            if ($pickupResult['success']) {
                                // Clear persistent target if we just picked it up
                                if (isset(self::$persistentTargets[$trackingKey]) && self::$persistentTargets[$trackingKey] === $currentPos) {
                                    error_log("DEBUG AI: Successfully picked up persistent target at {$currentPos} with replacement, clearing target");
                                    self::$persistentTargets[$trackingKey] = null;
                                    self::$persistentTargetReasons[$trackingKey] = null;
                                    self::$persistentPaths[$trackingKey] = [];
                                }
                                
                                $actions[] = $this->createAction('ai_info', ['message' => 'Item replaced, ending turn']);
                                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                                return;
                            }
                        }
                    }
                    
                    // If we get here, either it's not a weapon or not worth replacing
                    if (!isset(self::$unPickableItems[$trackingKey])) {
                        self::$unPickableItems[$trackingKey] = [];
                    }
                    self::$unPickableItems[$trackingKey][$currentPos] = true;
                    
                    // Also clear this from persistent targets since we can't/won't pick it up
                    if (isset(self::$persistentTargets[$trackingKey]) && self::$persistentTargets[$trackingKey] === $currentPos) {
                        error_log("DEBUG AI: Cannot pick up item at {$currentPos} (inventory full, not worth replacing), clearing target");
                        self::$persistentTargets[$trackingKey] = null;
                        self::$persistentTargetReasons[$trackingKey] = null;
                        self::$persistentPaths[$trackingKey] = [];
                    }
                    
                    $actions[] = $this->createAction('ai_info', ['message' => 'Inventory full, item not worth replacing']);
                    
                    // Mark current position as visited to prevent oscillation
                    $this->visitedPositions[$currentPos] = true;
                    
                    // Continue turn after failed pickup
                    $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
                    return; // IMPORTANT: Don't continue with general movement after handling item
                } else {
                    // Other failure, continue turn
                    $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
                    return; // IMPORTANT: Don't continue with general movement after handling item
                }
            } else {
                $actions[] = $this->createAction('item_reasoning', [
                    'decision' => 'Skip item',
                    'reason' => $reason
                ]);
                // Not picking up, continue turn
                $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
                return; // IMPORTANT: Don't continue with general movement after handling item
            }
            return;
        }
        
        // Handle battle if present
        if (isset($moveResponse['battleInfo']) && $moveResponse['battleInfo'] !== null) {
            $battleInfo = $moveResponse['battleInfo'];
            $battleResult = $battleInfo['result'] ?? 'unknown';
            
            error_log('DEBUG AI battle detected: ' . $battleResult);
            $actions[] = $this->createAction('battle_detected', ['battleResult' => $battleResult]);
            
            if ($battleResult === 'win') {
                $monster = $battleInfo['monsterType'] ?? 'unknown';
                $reward = $battleInfo['reward']['name'] ?? 'nothing';
                $actions[] = $this->createAction('battle_reasoning', [
                    'result' => 'victory',
                    'reason' => "Defeated $monster, earned $reward - checking for item pickup"
                ]);
                $this->handleBattleWin($gameId, $playerId, $currentTurnId, $moveResponse, $actions);
            } elseif ($battleResult === 'draw' || $battleResult === 'lose') {
                $monster = $battleInfo['monsterType'] ?? 'unknown';
                $damage = $battleInfo['totalDamage'] ?? 0;
                $monsterHP = $battleInfo['monster'] ?? 0;
                $actions[] = $this->createAction('battle_reasoning', [
                    'result' => $battleResult,
                    'reason' => $battleResult === 'draw' ? 
                        "Draw with $monster (both dealt $damage damage)" : 
                        "Lost to $monster (dealt $damage vs needed $monsterHP)"
                ]);
                $this->handleBattleLossOrDraw($gameId, $playerId, $currentTurnId, $battleInfo, $actions);
            } else {
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            }
        } else {
            // No battle, no item reported by API - but let's double-check for chests!
            error_log('DEBUG AI no battle or item detected by API, checking field for chest');
            
            // Check if we're actually standing on a chest that the API didn't report
            $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
            $field = $this->messageBus->dispatch(new GetField($gameId));
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
            
            if ($this->playerHasKey($player) && $this->positionHasChest($currentPosition->toString(), $field)) {
                $actions[] = $this->createAction('chest_found_on_check', [
                    'message' => 'Found chest at current position that API did not report!',
                    'position' => $currentPosition->toString()
                ]);
                // Try to pick up the chest
                [$x, $y] = explode(',', $currentPosition->toString());
                $pickupResult = $this->apiClient->pickItem($gameId, $playerId, $currentTurnId, (int)$x, (int)$y);
                $actions[] = $this->createAction('chest_pickup_attempt', ['result' => $pickupResult]);
                
                // Check if pickup actually succeeded - need to check response data
                $response = $pickupResult['response'] ?? [];
                $missingKey = $response['missingKey'] ?? false;
                $chestType = $response['chestType'] ?? null;
                
                if ($pickupResult['success'] && !$missingKey && $chestType) {
                    $actions[] = $this->createAction('treasure_collected', ['message' => 'Successfully collected treasure!']);
                    // Mark this chest as collected to avoid trying again
                    $this->collectedChests[$currentPosition->toString()] = true;
                } elseif ($missingKey) {
                    $actions[] = $this->createAction('chest_pickup_failed', [
                        'message' => 'Failed to pick up chest - missing key!',
                        'position' => $currentPosition->toString()
                    ]);
                    error_log('DEBUG AI: Chest pickup failed - missing key! AI thought it had a key but does not.');
                }
            }
            
            // Check if we're on a healing fountain and need healing before continuing
            $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
            $currentHP = $player->getHP();
            $maxHP = $player->getMaxHP();
            
            if ($currentHP < $maxHP) {
                $field = $this->messageBus->dispatch(new GetField($gameId));
                if ($this->isOnHealingFountain($currentPosition->toString(), $field)) {
                    $actions[] = $this->createAction('ai_reasoning', [
                        'decision' => 'End turn on healing fountain',
                        'reason' => "Reached healing fountain with {$currentHP}/{$maxHP} HP - ending turn to heal",
                        'priority' => -1
                    ]);
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    return;
                }
            }
            
            // Check if we've reached the maximum move count
            if ($this->moveCount >= self::MAX_MOVES_PER_TURN) {
                $actions[] = $this->createAction('ai_info', ['message' => 'Reached maximum moves for this turn']);
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                return;
            }
            
            $actions[] = $this->createAction('continue_turn', ['message' => 'Looking for more actions']);
            
            // Check if we're pursuing the dragon - maintain pursuit across moves
            $trackingKey = "{$gameId}_{$playerId}";
            if (isset(self::$pursuingDragon[$trackingKey]) && self::$pursuingDragon[$trackingKey] !== false) {
                $dragonPosition = self::$pursuingDragon[$trackingKey];
                $field = $this->messageBus->dispatch(new GetField($gameId));
                
                // Check if we're adjacent to dragon now
                $adjacentPositions = $this->getAdjacentPositions($dragonPosition);
                if (in_array($currentPosition->toString(), $adjacentPositions)) {
                    // We're adjacent! Attack the dragon
                    $actions[] = $this->createAction('ai_reasoning', [
                        'decision' => 'Attack dragon boss (adjacent after move)',
                        'reason' => "Now adjacent to dragon, attacking to win game!",
                        'priority' => 0.6
                    ]);
                    
                    // Clear pursuit flag
                    self::$pursuingDragon[$trackingKey] = false;
                    
                    // Attack dragon
                    [$toX, $toY] = explode(',', $dragonPosition);
                    [$fromX, $fromY] = explode(',', $currentPosition->toString());
                    $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                    $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                    
                    // This will handle the battle result
                    $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                    return;
                }
                
                // Continue moving toward dragon
                $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                    gameId: $gameId,
                    playerId: $playerId,
                    messageBus: $this->messageBus,
                ));
                $moveToOptions = $availablePlaces['moveTo'] ?? [];
                
                if (!empty($moveToOptions)) {
                    $placedTiles = $field->getPlacedTiles();
                    $bestMove = $this->findBestMoveToward($currentPosition->toString(), $dragonPosition, $moveToOptions, $placedTiles);
                    
                    // Check if we have a planned path to dragon
                    $trackingKey = "{$gameId}_{$playerId}";
                    if (!isset(self::$dragonPath[$trackingKey]) || empty(self::$dragonPath[$trackingKey])) {
                        // Plan a path to the dragon using BFS to avoid oscillation
                        error_log("DEBUG AI: Planning path to dragon at {$dragonPosition} from {$currentPosition->toString()}");
                        $path = $this->findPathToTarget($currentPosition->toString(), $dragonPosition, $placedTiles, $field);
                        if (!empty($path)) {
                            self::$dragonPath[$trackingKey] = $path;
                            error_log("DEBUG AI: Planned path to dragon: " . implode(' -> ', $path));
                        } else {
                            error_log("DEBUG AI: Could not find path to dragon!");
                        }
                    }
                    
                    // Follow the planned path
                    if (isset(self::$dragonPath[$trackingKey]) && !empty(self::$dragonPath[$trackingKey])) {
                        // Get next step in path
                        $nextStep = array_shift(self::$dragonPath[$trackingKey]);
                        
                        // Verify this step is in our available moves
                        if (in_array($nextStep, $moveOptions)) {
                            $bestMove = $nextStep;
                            error_log("DEBUG AI: Following planned path, next step: {$bestMove}");
                        } else {
                            error_log("DEBUG AI: Planned step {$nextStep} not available, replanning...");
                            // Replan if the planned step isn't available
                            unset(self::$dragonPath[$trackingKey]);
                            $bestMove = $this->findBestMoveToward($currentPosition->toString(), $dragonPosition, $moveOptions, $placedTiles);
                        }
                    } else if ($bestMove !== null) {
                        // Fallback to simple pathfinding if no planned path
                        error_log("DEBUG AI: No planned path, using simple pathfinding to move toward dragon");
                        
                        if ($bestMove !== null) {
                            $actions[] = $this->createAction('ai_reasoning', [
                                'decision' => 'Continue pursuit of dragon',
                                'reason' => "Moving toward dragon boss at {$dragonPosition}",
                                'priority' => 0.6
                            ]);
                            
                            [$toX, $toY] = explode(',', $bestMove);
                        [$fromX, $fromY] = explode(',', $currentPosition->toString());
                        $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                        $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                        
                        if ($moveResult['success']) {
                            $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                            return; // IMPORTANT: Return after handling move result to avoid falling through
                        } else {
                            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                            return;
                        }
                        } // Close the if ($bestMove !== null) block
                    }
                    // No valid unvisited moves toward dragon, end turn
                    error_log("DEBUG AI: No unvisited moves toward dragon, ending turn");
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    return;
                }
                // No moves available toward dragon at all, end turn
                error_log("DEBUG AI: No moves available toward dragon, ending turn");
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                return;
            }
            
            // This point should ONLY be reached if NOT pursuing dragon
            error_log("DEBUG AI: Not in dragon pursuit mode, checking for general actions");
            
            // Get available actions from current position
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            
            $moveToOptions = $availablePlaces['moveTo'] ?? [];
            $placeTileOptions = $availablePlaces['placeTile'] ?? [];
            
            // Validate moveToOptions - ensure they actually have tiles
            $field = $this->messageBus->dispatch(new GetField($gameId));
            $placedTiles = $field->getPlacedTiles();
            $validMoveOptions = [];
            foreach ($moveToOptions as $moveOption) {
                if (isset($placedTiles[$moveOption])) {
                    $validMoveOptions[] = $moveOption;
                } else {
                    error_log("DEBUG AI continueAfterAction: Move option {$moveOption} has no tile! Filtering out.");
                }
            }
            $moveToOptions = $validMoveOptions;
            
            error_log('DEBUG AI after move - valid moveToOptions: ' . json_encode($moveToOptions));
            error_log('DEBUG AI after move - placeTileOptions: ' . json_encode($placeTileOptions));
            error_log('DEBUG AI action count: ' . count($actions));
            
            // Check if we should continue moving towards better weapons
            $items = $field->getItems();
            $betterWeaponsOnField = [];
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
            $trackingKey = "{$gameId}_{$playerId}";
            
            foreach ($items as $pos => $item) {
                // Skip if this target is marked as unreachable
                if (isset(self::$unreachableTargets[$trackingKey][$pos])) {
                    continue;
                }
                
                // Skip if this item is marked as unpickable (inventory full)
                if (isset(self::$unPickableItems[$trackingKey][$pos])) {
                    continue;
                }
                
                if ($this->shouldPickupItem($item, $player)) {
                    $itemType = '';
                    if ($item instanceof \App\Game\Item\Item) {
                        $itemType = $item->type->value ?? '';
                    }
                    if (in_array($itemType, ['dagger', 'sword', 'axe'])) {
                        $betterWeaponsOnField[$pos] = $itemType;
                    }
                }
            }
            
            // Check if we have unvisited positions to move to
            $unvisitedOptions = array_filter($moveToOptions, fn($option) => !isset($this->visitedPositions[$option]));
            
            // Check if we're actively pursuing a monster for valuable loot
            $field = $this->messageBus->dispatch(new GetField($gameId));
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
            $monstersOnField = $this->getMonstersOnField($field, $player);
            $valuableMonsters = [];
            foreach ($monstersOnField as $monster) {
                if ($this->isItemWorthAttackingFor($monster['type'], $player)) {
                    $valuableMonsters[] = $monster;
                }
            }
            
            // Continue pursuing valuable monsters even through visited positions
            if (!empty($valuableMonsters) && !empty($moveToOptions) && $this->moveCount < self::MAX_MOVES_PER_TURN) {
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                $targetMonster = $this->chooseBestMonsterTarget($valuableMonsters, $player);
                if ($targetMonster) {
                    $actions[] = $this->createAction('ai_reasoning', ['message' => "Continuing pursuit of {$targetMonster['name']} for {$targetMonster['type']}"]);
                    // Store move count before attempting
                    $moveCountBefore = $this->moveCount;
                    $this->executeMoveTowardsMonster($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $targetMonster, $actions);
                    // Only return if we actually made progress
                    if ($this->moveCount > $moveCountBefore) {
                        return; // Successfully moved, exit
                    }
                    // Otherwise fall through to try other strategies
                    error_log("DEBUG AI: Monster pursuit failed, trying other strategies");
                }
            }
            
            // Try to continue moving towards better weapons if they exist (but check move limit)
            error_log("DEBUG AI: Checking weapon pursuit - weapons: " . json_encode($betterWeaponsOnField) . ", unvisited: " . count($unvisitedOptions) . ", moves: {$this->moveCount}");
            if (!empty($betterWeaponsOnField) && !empty($unvisitedOptions) && $this->moveCount < self::MAX_MOVES_PER_TURN) {
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                $actions[] = $this->createAction('ai_reasoning', ['message' => 'Continuing to move towards better weapons']);
                $this->executeMoveTowardsBetterWeapon($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $betterWeaponsOnField, $actions);
                // Don't return here - executeMoveTowardsBetterWeapon will handle continuation if successful
            } elseif (!empty($placeTileOptions) && $this->moveCount < self::MAX_MOVES_PER_TURN) {
                // Place tiles to open new areas (but only if we haven't reached max moves)
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                $actions[] = $this->createAction('ai_reasoning', ['message' => 'Placing tile to expand exploration area']);
                $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, 0);
                // Note: executeTilePlacement handles its own continuation
                return;
            } elseif (!empty($unvisitedOptions) && $this->moveCount < self::MAX_MOVES_PER_TURN) {
                // Continue exploring unvisited positions
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                $actions[] = $this->createAction('ai_reasoning', ['message' => 'Continuing exploration of unvisited areas']);
                $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
                // Note: executeMovement handles its own continuation
                return;
            } else {
                // No unvisited positions, no tiles to place, or reached move limit
                if (empty($unvisitedOptions)) {
                    $actions[] = $this->createAction('ai_info', ['message' => 'All reachable positions have been explored']);
                } else if ($this->moveCount >= self::MAX_MOVES_PER_TURN) {
                    $actions[] = $this->createAction('ai_info', ['message' => 'Reached maximum moves for this turn']);
                } else {
                    $actions[] = $this->createAction('ai_info', ['message' => 'No more actions available']);
                }
                // Always end turn here
                error_log("DEBUG AI: Ending turn in continueAfterAction - no more strategies");
                $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            }
        }
    }
    
    /**
     * Handle battle win: pickup with inventory management
     */
    private function handleBattleWin(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array $moveResponse, array &$actions): void
    {
        error_log('DEBUG AI handleBattleWin called');
        
        // Get player position for pickup - it's in battleInfo.position
        $battleInfo = $moveResponse['battleInfo'] ?? null;
        $playerPosition = $battleInfo['position'] ?? null;
        error_log('DEBUG AI playerPosition from battleInfo: ' . ($playerPosition ?: 'null'));
        
        if (!$playerPosition) {
            error_log('DEBUG AI no playerPosition, continuing turn');
            // No position means we can't pick up, but continue playing
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
            return;
        }
        
        // Check the reward type before trying to pick it up
        $reward = $battleInfo['reward'] ?? null;
        if ($reward) {
            $rewardType = $reward['type'] ?? '';
            $rewardName = $reward['name'] ?? '';
            
            // If it's a key and we already have one, skip pickup
            if ($rewardType === 'key') {
                $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
                if ($this->playerHasKey($player)) {
                    error_log('DEBUG AI: Already have a key, skipping key pickup from battle reward');
                    $actions[] = $this->createAction('skip_pickup', [
                        'reason' => 'Already have a key in inventory, leaving this one for later',
                        'itemType' => 'key'
                    ]);
                    
                    // Battle ends turn, so end it
                    $actions[] = $this->createAction('ai_info', ['message' => 'Battle occurred, ending turn']);
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    return;
                }
            }
        }
        
        [$x, $y] = explode(',', $playerPosition);
        error_log('DEBUG AI attempting pickup at: ' . $x . ',' . $y);
        
        // Call pick-item with pickup: true
        $pickupResult = $this->apiClient->pickItem($gameId, $playerId, $currentTurnId, (int)$x, (int)$y);
        $actions[] = $this->createAction('pickup_attempt', ['result' => $pickupResult]);
        error_log('DEBUG AI pickupResult: ' . json_encode($pickupResult, JSON_PRETTY_PRINT));
        
        if ($pickupResult['success']) {
            // Pickup successful, but check if we should replace items
            error_log('DEBUG AI pickup successful, checking if inventory management needed');
            
            $response = $pickupResult['response'] ?? [];
            
            // Check if inventory is full after successful pickup
            if (isset($response['inventoryFull']) && $response['inventoryFull'] === true) {
                error_log('DEBUG AI inventory full after successful pickup - should evaluate replacements');
                
                // Check what we picked up
                $pickedUpItem = $response['item'] ?? null;
                $currentInventory = $response['currentInventory'] ?? [];
                
                if ($pickedUpItem) {
                    $itemType = $pickedUpItem['type'] ?? '';
                    error_log("DEBUG AI picked up {$itemType}, inventory full, current inventory: " . json_encode($currentInventory));
                    
                    // If we picked up a weapon and inventory is full, check if we should drop a weaker one
                    if (in_array($itemType, ['dagger', 'sword', 'axe'])) {
                        // Weapon priority: axe > sword > dagger
                        $weaponPriority = ['dagger' => 1, 'sword' => 2, 'axe' => 3];
                        $newWeaponPriority = $weaponPriority[$itemType] ?? 0;
                        
                        // Find if we have any weaker weapons in inventory
                        // Note: currentInventory might not include the just-picked item
                        $hasWeakerWeapon = false;
                        foreach ($currentInventory as $invItem) {
                            $invItemType = $invItem['type'] ?? '';
                            $invItemPriority = $weaponPriority[$invItemType] ?? 0;
                            
                            // Check if this inventory item is weaker than what we just picked up
                            if ($invItemPriority > 0 && $invItemPriority < $newWeaponPriority) {
                                $hasWeakerWeapon = true;
                                error_log("DEBUG AI found weaker weapon: {$invItemType} (priority {$invItemPriority}) vs new {$itemType} (priority {$newWeaponPriority})");
                                break;
                            }
                        }
                        
                        if ($hasWeakerWeapon) {
                            error_log('DEBUG AI should drop weaker weapon to optimize inventory');
                            $actions[] = $this->createAction('ai_reasoning', [
                                'message' => "Picked up {$itemType} with inventory full. Dropping weaker weapon to optimize loadout."
                            ]);
                            
                            // Handle the replacement by dropping the weakest weapon
                            $this->handlePostPickupReplacement($gameId, $playerId, $currentTurnId, $response, $actions);
                            return;
                        } else {
                            error_log("DEBUG AI no weaker weapons to replace, keeping current inventory");
                            $actions[] = $this->createAction('ai_reasoning', [
                                'message' => "Picked up {$itemType} but no weaker weapons to replace."
                            ]);
                        }
                    }
                }
            }
            
            // Continue playing normally
            error_log('DEBUG AI pickup successful, continuing turn');
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
        } elseif (!$pickupResult['success']) {
            // Check the response for inventory full indication
            $response = $pickupResult['response'] ?? [];
            $errorMessage = $response['message'] ?? '';
            
            // Check if inventory is full from the response structure
            if (isset($response['inventoryFull']) && $response['inventoryFull'] === true) {
                error_log('DEBUG AI inventory full detected from response');
                
                // Check if it's a key (can only have 1 key, so skip)
                if (isset($response['itemCategory']) && $response['itemCategory'] === 'key') {
                    error_log('DEBUG AI already has a key, skipping pickup');
                    $actions[] = $this->createAction('skip_pickup', ['reason' => 'Already has a key']);
                    $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
                } else {
                    // Try to pick up with replacement for weapons/spells
                    $this->handleInventoryFullWithReplacement($gameId, $playerId, $currentTurnId, $battleInfo, (int)$x, (int)$y, $actions);
                }
            } elseif (strpos($errorMessage, 'Inventory for') !== false && strpos($errorMessage, 'is full') !== false) {
                // Fallback: Check error message if response structure doesn't have inventoryFull
                error_log('DEBUG AI inventory full detected from error message');
                
                // Extract item type from error message or battleInfo
                $reward = $battleInfo['reward'] ?? null;
                if ($reward && $reward['type'] === 'key') {
                    error_log('DEBUG AI already has a key, skipping pickup');
                    $actions[] = $this->createAction('skip_pickup', ['reason' => 'Already has a key']);
                    $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
                } else {
                    $this->handleInventoryFullWithReplacement($gameId, $playerId, $currentTurnId, $battleInfo, (int)$x, (int)$y, $actions);
                }
            } else {
                // Other error, continue playing anyway
                error_log('DEBUG AI pickup failed: ' . $errorMessage);
                $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
            }
        } else {
            // Pickup failed for unknown reasons, continue playing
            error_log('DEBUG AI pickup failed for unknown reasons, continuing turn');
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
        }
    }
    
    /**
     * Check if a battle has occurred in the current turn
     */
    private function hasBattleOccurred(array $actions): bool
    {
        foreach ($actions as $action) {
            if ($action['type'] === 'battle_detected') {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Continue playing after an action (battle, pickup, etc.)
     */
    private function continueAfterAction(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array &$actions, int $recursionDepth = 0): void
    {
        // Prevent infinite recursion
        if ($recursionDepth > 10) {
            error_log("DEBUG AI: Maximum recursion depth reached in continueAfterAction, ending turn");
            $actions[] = $this->createAction('ai_info', ['message' => 'Maximum recursion depth reached']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Also check total action count to prevent infinite loops
        if (count($actions) > self::MAX_ACTIONS_PER_TURN) {
            error_log("DEBUG AI: Maximum action count reached (" . self::MAX_ACTIONS_PER_TURN . "), ending turn");
            $actions[] = $this->createAction('ai_info', ['message' => 'Maximum action count reached']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Check if battle occurred - if so, must end turn (can't move after battle)
        if ($this->hasBattleOccurred($actions)) {
            $actions[] = $this->createAction('ai_info', ['message' => 'Battle occurred, ending turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // First, check if there's a dragon on the field and deck is empty
        // This ensures we always pursue the dragon when it's the only option
        $field = $this->messageBus->dispatch(new GetField($gameId));
        $deck = $this->messageBus->dispatch(new GetDeck($gameId));
        $items = $field->getItems();
        
        $dragonOnField = null;
        $otherMonstersExist = false;
        foreach ($items as $position => $item) {
            if ($item instanceof \App\Game\Item\Item) {
                $itemName = $item->name->value ?? 'unknown';
                // Check if it's a dragon
                if ($itemName === 'dragon') {
                    $dragonOnField = $position;
                    error_log("DEBUG AI: Found dragon at position {$position}");
                }
                // Check if it's another monster (has HP and not defeated)
                elseif (!$item->guardDefeated && $item->guardHP > 0 && $itemName !== 'dragon') {
                    $otherMonstersExist = true;
                    error_log("DEBUG AI: Found other monster: {$itemName} at {$position}");
                }
            }
        }
        
        // If dragon exists and is the only monster with empty deck, ALWAYS pursue it
        $trackingKey = "{$gameId}_{$playerId}";
        if ($dragonOnField && !$otherMonstersExist && $deck->isEmpty()) {
            // Always set/maintain the pursuit flag when dragon is the only option
            self::$pursuingDragon[$trackingKey] = $dragonOnField;
            error_log("DEBUG AI: Dragon is only monster with empty deck - maintaining pursuit for position {$dragonOnField}");
        }
        
        // Check if we're pursuing the dragon - if so, continue pursuit
        error_log("DEBUG AI: Checking dragon pursuit flag at start of continueAfterAction for key {$trackingKey}");
        if (isset(self::$pursuingDragon[$trackingKey])) {
            error_log("DEBUG AI: Dragon pursuit flag value: " . json_encode(self::$pursuingDragon[$trackingKey]));
        }
        
        if (isset(self::$pursuingDragon[$trackingKey]) && self::$pursuingDragon[$trackingKey] !== false) {
            $dragonPosition = self::$pursuingDragon[$trackingKey];
            error_log("DEBUG AI: Dragon pursuit active! Continuing pursuit to position {$dragonPosition}");
            $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
            
            // Check if we're adjacent to dragon now
            $adjacentPositions = $this->getAdjacentPositions($dragonPosition);
            if (in_array($currentPosition->toString(), $adjacentPositions)) {
                // We're adjacent! Attack the dragon
                $actions[] = $this->createAction('ai_reasoning', [
                    'decision' => 'Attack dragon boss (continuing pursuit)',
                    'reason' => "Adjacent to dragon, attacking to win game!",
                    'priority' => 0.6
                ]);
                
                // Clear pursuit flag and path
                self::$pursuingDragon[$trackingKey] = false;
                unset(self::$dragonPath[$trackingKey]);
                
                // Attack dragon
                [$toX, $toY] = explode(',', $dragonPosition);
                [$fromX, $fromY] = explode(',', $currentPosition->toString());
                $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                
                $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                return;
            }
            
            // Continue moving toward dragon
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            $moveToOptions = $availablePlaces['moveTo'] ?? [];
            
            if (!empty($moveToOptions)) {
                $placedTiles = $field->getPlacedTiles();
                
                // Filter out visited positions to avoid oscillation
                $unvisitedMoveOptions = array_filter($moveToOptions, fn($option) => !isset($this->visitedPositions[$option]));
                
                // If we have unvisited options, use those; otherwise, check if we should end turn
                if (!empty($unvisitedMoveOptions)) {
                    $bestMove = $this->findBestMoveToward($currentPosition->toString(), $dragonPosition, $unvisitedMoveOptions, $placedTiles);
                } else {
                    // All move options have been visited
                    error_log("DEBUG AI: All move options visited while pursuing dragon, ending turn to avoid oscillation");
                    $actions[] = $this->createAction('ai_info', ['message' => 'All positions visited this turn, ending turn']);
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    return;
                }
                
                if ($bestMove !== null) {
                    // Check if we're stuck oscillating
                    $lastMoves = [];
                    foreach ($actions as $action) {
                        if ($action['type'] === 'move_player' && isset($action['result']['to'])) {
                            $lastMoves[] = $action['result']['to'];
                        }
                    }
                    
                    // Additional check: If we've been to this position recently in this turn, try alternative moves
                    if (in_array($bestMove, $lastMoves)) {
                        error_log("DEBUG AI: Best move {$bestMove} was already visited this turn, looking for alternatives");
                        // Filter out the best move and find next best
                        $alternativeMoves = array_filter($moveToOptions, fn($m) => $m !== $bestMove && !in_array($m, $lastMoves));
                        if (!empty($alternativeMoves)) {
                            $bestMove = $this->findBestMoveToward($currentPosition->toString(), $dragonPosition, $alternativeMoves, $placedTiles);
                        } else {
                            $bestMove = null; // No alternatives
                        }
                    }
                    
                    if ($bestMove !== null) {
                        error_log("DEBUG AI: Found best move toward dragon: {$bestMove} from {$currentPosition->toString()}");
                        $actions[] = $this->createAction('ai_reasoning', [
                            'decision' => 'Continue pursuit of dragon',
                            'reason' => "Moving toward dragon boss at {$dragonPosition}",
                            'priority' => 0.6
                        ]);
                        
                        [$toX, $toY] = explode(',', $bestMove);
                        [$fromX, $fromY] = explode(',', $currentPosition->toString());
                        $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                        $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                        
                        // IMPORTANT: Don't call handleMoveResult here to avoid infinite recursion
                        // Just check if we can continue moving
                        if ($moveResult['success'] && $this->moveCount < self::MAX_MOVES_PER_TURN - 1) {
                            // Mark the position as visited to prevent oscillation
                            $this->visitedPositions[$bestMove] = true;
                            
                            // Increment move count
                            $this->moveCount++;
                            error_log("DEBUG AI: Dragon pursuit move successful, continuing pursuit (move count: {$this->moveCount})");
                            
                            // Check if the new position has an item
                            if (isset($moveResult['response']['itemInfo']) && $moveResult['response']['itemInfo'] !== null) {
                                // Handle the item at the new position
                                $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                            } else {
                                // Can make more moves, recurse to continue pursuit
                                $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions, $recursionDepth + 1);
                            }
                        } else {
                            // Either failed or reached move limit
                            if (!$moveResult['success']) {
                                error_log("DEBUG AI: Dragon pursuit move failed: " . json_encode($moveResult));
                            } else {
                                error_log("DEBUG AI: Reached move limit during dragon pursuit");
                            }
                            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                        }
                        return;
                    }
                    
                    // No valid moves toward dragon, end turn
                    error_log("DEBUG AI: No valid moves toward dragon, ending turn but maintaining pursuit flag");
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    return;
                }
            }
            
            // If we get here in dragon pursuit mode, we've handled the pursuit
            error_log("DEBUG AI: Dragon pursuit logic completed in continueAfterAction");
            return; // Exit to avoid falling into exploration
        }
        
        // Double-check: if dragon is the only monster and deck is empty, we should be pursuing
        // This catches any case where the flag might have been lost
        if ($dragonOnField && !$otherMonstersExist && $deck->isEmpty()) {
            error_log("DEBUG AI: Dragon is only option but pursuit flag was not set - forcing pursuit mode");
            self::$pursuingDragon[$trackingKey] = $dragonOnField;
            
            // Re-run the pursuit logic
            $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            $moveToOptions = $availablePlaces['moveTo'] ?? [];
            
            if (!empty($moveToOptions)) {
                $placedTiles = $field->getPlacedTiles();
                
                // Filter out visited positions to avoid oscillation
                $unvisitedMoveOptions = array_filter($moveToOptions, fn($option) => !isset($this->visitedPositions[$option]));
                
                if (!empty($unvisitedMoveOptions)) {
                    $bestMove = $this->findBestMoveToward($currentPosition->toString(), $dragonOnField, $unvisitedMoveOptions, $placedTiles);
                } else {
                    // All positions visited, end turn
                    error_log("DEBUG AI: All move options visited during dragon recovery, ending turn");
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    return;
                }
                
                if ($bestMove !== null) {
                    $actions[] = $this->createAction('ai_reasoning', [
                        'decision' => 'Continue pursuit of dragon (recovery)',
                        'reason' => "Moving toward dragon boss at {$dragonOnField} - only option",
                        'priority' => 0.6
                    ]);
                    
                    [$toX, $toY] = explode(',', $bestMove);
                    [$fromX, $fromY] = explode(',', $currentPosition->toString());
                    $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                    $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                    
                    if ($moveResult['success'] && $this->moveCount < self::MAX_MOVES_PER_TURN - 1) {
                        // Mark position as visited
                        $this->visitedPositions[$bestMove] = true;
                        $this->moveCount++;
                        $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions, $recursionDepth + 1);
                    } else {
                        $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                        $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    }
                    return;
                }
            }
            
            // No valid moves, end turn
            error_log("DEBUG AI: No moves available for dragon pursuit, ending turn");
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // ALWAYS prioritize dragon pursuit over other actions
        $trackingKey = "{$gameId}_{$playerId}";
        error_log("DEBUG AI: Checking dragon pursuit before healing/exploration - key: {$trackingKey}");
        if (isset(self::$pursuingDragon[$trackingKey])) {
            error_log("DEBUG AI: Dragon pursuit flag exists with value: " . json_encode(self::$pursuingDragon[$trackingKey]));
        }
        
        if (isset(self::$pursuingDragon[$trackingKey]) && self::$pursuingDragon[$trackingKey] !== false) {
            // We're pursuing the dragon, don't get distracted by healing or exploration
            error_log("DEBUG AI: Dragon pursuit is active, skipping healing/exploration checks");
            // Skip healing check and go directly to continuing actions
        } else {
            // Check if we're on a healing fountain and need healing (only if NOT pursuing dragon)
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
            $currentHP = $player->getHP();
            $maxHP = $player->getMaxHP();
            
            if ($currentHP < $maxHP) {
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                $field = $this->messageBus->dispatch(new GetField($gameId));
                
                if ($this->isOnHealingFountain($currentPosition->toString(), $field)) {
                    $actions[] = $this->createAction('ai_reasoning', [
                        'decision' => 'End turn on healing fountain',
                        'reason' => "On healing fountain with {$currentHP}/{$maxHP} HP - ending turn to heal",
                        'priority' => -1
                    ]);
                    $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                    $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    return;
                }
            }
        }
        
        // Check action limit
        if (count($actions) > self::MAX_ACTIONS_PER_TURN) {
            $actions[] = $this->createAction('ai_info', ['message' => 'Action limit reached, ending turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Check if we're still pursuing the dragon - if so, don't do general exploration
        $trackingKey = "{$gameId}_{$playerId}";
        error_log("DEBUG AI: Final check before general exploration - dragon pursuit flag for {$trackingKey}");
        if (isset(self::$pursuingDragon[$trackingKey])) {
            error_log("DEBUG AI: Dragon pursuit flag value before exploration: " . json_encode(self::$pursuingDragon[$trackingKey]));
        }
        
        if (isset(self::$pursuingDragon[$trackingKey]) && self::$pursuingDragon[$trackingKey] !== false) {
            // Dragon pursuit is active but we couldn't find a valid move
            // This can happen if we're stuck or oscillating
            // End turn rather than falling back to general exploration
            error_log("DEBUG AI: Dragon pursuit active but no valid moves found, ending turn to maintain focus");
            $actions[] = $this->createAction('ai_info', ['message' => 'Dragon pursuit blocked, ending turn to maintain focus']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Final failsafe: if dragon exists as only monster and deck is empty, we MUST be pursuing
        if ($dragonOnField && !$otherMonstersExist && $deck->isEmpty()) {
            error_log("DEBUG AI: CRITICAL: Dragon is only monster but pursuit flag was false - forcing dragon pursuit!");
            self::$pursuingDragon[$trackingKey] = $dragonOnField;
            
            // Get current position and available moves
            $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            $moveToOptions = $availablePlaces['moveTo'] ?? [];
            
            if (!empty($moveToOptions)) {
                $placedTiles = $field->getPlacedTiles();
                $bestMove = $this->findBestMoveToward($currentPosition->toString(), $dragonOnField, $moveToOptions, $placedTiles);
                
                if ($bestMove !== null) {
                    $actions[] = $this->createAction('ai_reasoning', [
                        'decision' => 'Continue pursuit of dragon (final failsafe)',
                        'reason' => "MUST pursue dragon at {$dragonOnField} - it's the only option",
                        'priority' => 0.6
                    ]);
                    
                    [$toX, $toY] = explode(',', $bestMove);
                    [$fromX, $fromY] = explode(',', $currentPosition->toString());
                    $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                    $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                    
                    if ($moveResult['success'] && $this->moveCount < self::MAX_MOVES_PER_TURN - 1) {
                        $this->moveCount++;
                        $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions, $recursionDepth + 1);
                    } else {
                        $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                        $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    }
                    return;
                }
            }
            
            // No moves available, end turn
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        error_log("DEBUG AI: No dragon pursuit needed, proceeding with general exploration");
        
        // Get available actions from current position (only for non-dragon pursuit)
        $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
            gameId: $gameId,
            playerId: $playerId,
            messageBus: $this->messageBus,
        ));
        
        $moveToOptions = $availablePlaces['moveTo'] ?? [];
        $placeTileOptions = $availablePlaces['placeTile'] ?? [];
        
        // Try to place more tiles or move
        if (!empty($placeTileOptions)) {
            $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
            $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions);
        } elseif (!empty($moveToOptions)) {
            $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
            $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
        } else {
            // No more actions available, end turn
            $actions[] = $this->createAction('ai_info', ['message' => 'No more actions available']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
        }
    }
    
    /**
     * Handle post-pickup replacement when inventory is full
     * This is called after successfully picking up an item when inventory becomes full
     * At this point, the item is already picked up, so we need to use inventory-action API
     * to replace one of the existing items
     */
    private function handlePostPickupReplacement(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array $pickupResponse, array &$actions): void
    {
        $pickedUpItem = $pickupResponse['item'] ?? null;
        $currentInventory = $pickupResponse['currentInventory'] ?? [];
        
        if (!$pickedUpItem) {
            $actions[] = $this->createAction('ai_reasoning', ['message' => 'No item info available for replacement decision']);
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions, $recursionDepth + 1);
            return;
        }
        
        $newItemType = $pickedUpItem['type'] ?? '';
        
        // Find the weakest item to replace
        $itemToReplace = null;
        $replaceReason = '';
        
        if (in_array($newItemType, ['dagger', 'sword', 'axe'])) {
            // Weapon priority: axe > sword > dagger
            $weaponPriority = ['dagger' => 1, 'sword' => 2, 'axe' => 3];
            $newPriority = $weaponPriority[$newItemType] ?? 0;
            
            $weakestWeapon = null;
            $weakestPriority = 999;
            
            // Find the weakest weapon in current inventory to replace
            // Note: currentInventory shows what was there BEFORE the pickup
            foreach ($currentInventory as $invItem) {
                $itemType = $invItem['type'] ?? '';
                
                if (in_array($itemType, ['dagger', 'sword', 'axe'])) {
                    $priority = $weaponPriority[$itemType] ?? 0;
                    if ($priority < $weakestPriority && $priority < $newPriority) {
                        $weakestPriority = $priority;
                        $weakestWeapon = $invItem;
                    }
                }
            }
            
            // Replace the weakest weapon if new one is better
            if ($weakestWeapon) {
                $itemToReplace = $weakestWeapon['itemId'];
                $replaceReason = "Replacing {$weakestWeapon['type']} (priority {$weakestPriority}) with {$newItemType} (priority {$newPriority})";
            }
        }
        
        if ($itemToReplace) {
            $actions[] = $this->createAction('ai_reasoning', ['message' => $replaceReason]);
            $actions[] = $this->createAction('replace_item_decision', [
                'itemToReplace' => $itemToReplace, 
                'newItem' => $pickedUpItem,
                'reason' => $replaceReason
            ]);
            
            // Use the inventory-action API to replace the item
            // We need to pass the full item object and the ID of item to replace
            $replaceResult = $this->callInventoryReplaceAction(
                $gameId,
                $playerId, 
                $currentTurnId,
                $pickedUpItem,
                $itemToReplace,
                $actions
            );
            
            if ($replaceResult) {
                $actions[] = $this->createAction('ai_reasoning', ['message' => 'Successfully replaced weaker weapon']);
            }
        } else {
            $actions[] = $this->createAction('ai_reasoning', ['message' => 'All current items are equal or better, keeping inventory as is']);
        }
        
        // After handling inventory, continue playing (but remember battle ends turn)
        $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions, $recursionDepth + 1);
    }
    
    /**
     * Call the inventory-action API to replace an item
     */
    private function callInventoryReplaceAction(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array $newItem, string $itemIdToReplace, array &$actions): bool
    {
        error_log('DEBUG AI calling inventory-action API to replace item');
        error_log('DEBUG AI newItem: ' . json_encode($newItem));
        error_log('DEBUG AI itemIdToReplace: ' . $itemIdToReplace);
        
        // Call the updated inventoryAction method with correct parameters
        $result = $this->apiClient->inventoryAction(
            $gameId,
            $playerId,
            $currentTurnId,
            'replace',
            $newItem,  // Pass the full item object
            \App\Infrastructure\Uuid\Uuid::fromString($itemIdToReplace)  // ID of item to replace
        );
        
        $actions[] = $this->createAction('inventory_replace', ['result' => $result]);
        
        if (!$result['success']) {
            error_log('DEBUG AI inventory replace failed: ' . json_encode($result));
        } else {
            error_log('DEBUG AI successfully replaced item');
        }
        
        return $result['success'] ?? false;
    }
    
    /**
     * Handle inventory full by choosing an item to replace
     */
    private function handleInventoryFullWithReplacement(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array $battleInfo, int $x, int $y, array &$actions): void
    {
        // Get current player inventory
        $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
        $inventory = $player->getInventory();
        
        // Determine what type of item we're trying to pick up
        $reward = $battleInfo['reward'] ?? null;
        if (!$reward) {
            $actions[] = $this->createAction('ai_reasoning', ['message' => 'No reward information available, continuing turn']);
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions, $recursionDepth + 1);
            return;
        }
        
        $newItemType = $reward['type'] ?? '';
        $actions[] = $this->createAction('inventory_full', [
            'newItemType' => $newItemType,
            'reasoning' => "Inventory is full, evaluating if {$newItemType} is better than current items"
        ]);
        
        // Get weapons and spells from inventory - they're in category arrays
        $weapons = isset($inventory['weapon']) ? $inventory['weapon'] : [];
        $spells = isset($inventory['spell']) ? $inventory['spell'] : [];
        
        // Find worst item to replace based on type
        $itemToReplace = null;
        $replacementReason = '';
        
        if (in_array($newItemType, ['dagger', 'sword', 'axe'])) {
            // Weapon - find weakest weapon to replace
            $weaponPriority = ['dagger' => 1, 'sword' => 2, 'axe' => 3];
            $newPriority = $weaponPriority[$newItemType] ?? 0;
            
            $weakestWeapon = null;
            $weakestPriority = 999;
            
            foreach ($weapons as $weapon) {
                // Handle both array and Item object formats
                if ($weapon instanceof \App\Game\Item\Item) {
                    $weaponType = $weapon->type->value ?? '';
                    $weaponArray = ['type' => $weaponType, 'itemId' => $weapon->itemId->toString()];
                } else {
                    $weaponType = $weapon['type'] ?? '';
                    $weaponArray = $weapon;
                }
                
                $currentPriority = $weaponPriority[$weaponType] ?? 0;
                if ($currentPriority < $weakestPriority) {
                    $weakestPriority = $currentPriority;
                    $weakestWeapon = $weaponArray;
                }
            }
            
            // Only replace if new weapon is better than the weakest we have
            if ($weakestWeapon && $newPriority > $weakestPriority) {
                $itemToReplace = $weakestWeapon['itemId'];
                $replacementReason = "Replacing {$weakestWeapon['type']} (priority {$weakestPriority}) with {$newItemType} (priority {$newPriority}) - better weapon!";
            } else {
                $replacementReason = "Not replacing - {$newItemType} (priority {$newPriority}) is not better than current weapons";
            }
        } elseif (in_array($newItemType, ['fireball', 'teleport'])) {
            // Spell - fireball is better than teleport
            $spellPriority = ['teleport' => 1, 'fireball' => 2];
            $newPriority = $spellPriority[$newItemType] ?? 0;
            
            if ($newItemType === 'fireball') {
                // Replace teleport with fireball if we have one
                foreach ($spells as $spell) {
                    // Handle both array and Item object formats
                    if ($spell instanceof \App\Game\Item\Item) {
                        $spellType = $spell->type->value ?? '';
                        $spellId = $spell->itemId->toString();
                    } else {
                        $spellType = $spell['type'] ?? '';
                        $spellId = $spell['itemId'] ?? '';
                    }
                    
                    if ($spellType === 'teleport') {
                        $itemToReplace = $spellId;
                        $replacementReason = "Replacing teleport with fireball - fireball adds damage in combat!";
                        break;
                    }
                }
                
                if (!$itemToReplace && !empty($spells)) {
                    // Replace any spell with fireball as it's better
                    $firstSpell = $spells[0];
                    if ($firstSpell instanceof \App\Game\Item\Item) {
                        $itemToReplace = $firstSpell->itemId->toString();
                        $replacementReason = "Replacing {$firstSpell->type->value} with fireball - fireball is best spell!";
                    } else {
                        $itemToReplace = $firstSpell['itemId'];
                        $replacementReason = "Replacing {$firstSpell['type']} with fireball - fireball is best spell!";
                    }
                }
            } else if ($newItemType === 'teleport' && empty($spells)) {
                // Only pick up teleport if we have no spells
                $replacementReason = "Not replacing - teleport is less useful than existing items";
            }
        }
        
        if ($itemToReplace) {
            // Try pickup with replacement
            $actions[] = $this->createAction('ai_reasoning', ['message' => $replacementReason]);
            $actions[] = $this->createAction('replace_item', ['replacing' => $itemToReplace, 'reason' => $replacementReason]);
            $pickupResult = $this->apiClient->pickItem($gameId, $playerId, $currentTurnId, $x, $y, $itemToReplace);
            $actions[] = $this->createAction('pickup_with_replace', ['result' => $pickupResult]);
        } else {
            $actions[] = $this->createAction('ai_reasoning', ['message' => $replacementReason]);
            $actions[] = $this->createAction('skip_pickup', ['reason' => 'New item not better than current inventory']);
        }
        
        // Continue playing regardless of pickup result
        $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions, $recursionDepth + 1);
    }
    
    /**
     * Handle inventory full situation: analyze and replace items if new one is better
     * @deprecated Use handleInventoryFullWithReplacement instead
     */
    private function handleInventoryFullPickup(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array $pickupResponse, array &$actions): void
    {
        $currentInventory = $pickupResponse['currentInventory'] ?? [];
        $newItem = $pickupResponse['item'] ?? [];
        
        if (empty($newItem) || empty($currentInventory)) {
            // Can't analyze, just end turn
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        $newItemType = $newItem['type'] ?? '';
        $itemToReplace = $this->findItemToReplace($currentInventory, $newItemType);
        
        $actions[] = $this->createAction('inventory_analysis', [
            'newItemType' => $newItemType,
            'itemToReplace' => $itemToReplace
        ]);
        
        if ($itemToReplace !== null) {
            // New item is better, replace the old one
            $replaceResult = $this->apiClient->inventoryAction(
                $gameId,
                $playerId,
                $currentTurnId,
                'replace',
                \App\Infrastructure\Uuid\Uuid::fromString($newItem['itemId']),
                \App\Infrastructure\Uuid\Uuid::fromString($itemToReplace['itemId'])
            );
            
            $actions[] = $this->createAction('inventory_replace', ['result' => $replaceResult]);
        }
        
        // Always end turn after inventory handling
        $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
        $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
    }
    
    /**
     * Handle battle loss or draw - finalize with consumables if beneficial
     */
    private function handleBattleLossOrDraw(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array $battleInfo, array &$actions): void
    {
        $battleId = \App\Infrastructure\Uuid\Uuid::fromString($battleInfo['battleId']);
        $needsConsumableConfirmation = $battleInfo['needsConsumableConfirmation'] ?? false;
        $availableConsumables = $battleInfo['availableConsumables'] ?? [];
        $battleResult = $battleInfo['result'] ?? 'unknown';
        $monsterHP = $battleInfo['monster'] ?? 0;
        $totalDamage = $battleInfo['totalDamage'] ?? 0;
        $monsterType = $battleInfo['monsterType'] ?? 'unknown';
        
        $selectedConsumableIds = [];
        $pickupItem = false;
        
        // Calculate how many consumables we need to win
        $damageNeeded = $monsterHP - $totalDamage;
        $fireballsNeeded = $damageNeeded; // Each fireball adds 1 damage
        
        // Use consumables if we can turn a loss/draw into a win
        if (($battleResult === 'draw' || $battleResult === 'lose') && !empty($availableConsumables) && $damageNeeded > 0) {
            $fireballsUsed = 0;
            
            // Use fireballs to win (each adds 1 damage)
            foreach ($availableConsumables as $consumable) {
                if ($consumable['type'] === 'fireball' && $fireballsUsed < $fireballsNeeded) {
                    $selectedConsumableIds[] = \App\Infrastructure\Uuid\Uuid::fromString($consumable['itemId']);
                    $fireballsUsed++;
                    
                    // Check if this is the dragon boss
                    $reason = ($monsterType === 'dragon') 
                        ? "To defeat dragon boss and win game!" 
                        : "To turn {$battleResult} into victory";
                    
                    $actions[] = $this->createAction('using_consumable', [
                        'type' => 'fireball',
                        'reason' => $reason,
                        'damage_added' => 1,
                        'total_after' => $totalDamage + $fireballsUsed
                    ]);
                    
                    // If we now have enough damage to win, we'll pick up the item
                    if ($fireballsUsed >= $fireballsNeeded) {
                        $pickupItem = true;
                        break;
                    }
                }
            }
        }
        
        // Finalize battle
        if ($needsConsumableConfirmation) {
            $finalizeResult = $this->apiClient->finalizeBattle(
                $gameId,
                $playerId,
                $currentTurnId,
                $battleId,
                $selectedConsumableIds,
                $pickupItem
            );
            $actions[] = $this->createAction('finalize_battle', ['result' => $finalizeResult]);
            
            // Check if we won with consumables
            $finalBattleResult = $finalizeResult['response']['battleResult'] ?? $battleResult;
            if ($finalBattleResult === 'win' && $pickupItem) {
                // We won! Check if this was the dragon
                if ($monsterType === 'dragon') {
                    $actions[] = $this->createAction('game_won', [
                        'message' => 'Defeated dragon boss with consumables! Game won!',
                        'final_damage' => $totalDamage + count($selectedConsumableIds)
                    ]);
                }
            } else if ($finalBattleResult === 'lose') {
                // Still lost even with consumables - player was likely moved back
                $actions[] = $this->createAction('ai_info', [
                    'message' => 'Lost battle even with consumables, position may have changed'
                ]);
                
                // Clear dragon pursuit flag if we lost to the dragon
                // But we'll re-evaluate on next turn if we have consumables
                if ($monsterType === 'dragon') {
                    $trackingKey = "{$gameId}_{$playerId}";
                    self::$pursuingDragon[$trackingKey] = false;
                    $actions[] = $this->createAction('ai_info', [
                        'message' => 'Lost to dragon, but will try again with consumables on next turn'
                    ]);
                }
            }
        }
        
        // End turn
        $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
        $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
    }
    
    /**
     * Find an item to replace based on priority: dagger < sword < axe, fireball > teleport
     */
    private function findItemToReplace(array $inventory, string $newItemType): ?array
    {
        $weaponPriority = ['dagger' => 1, 'sword' => 2, 'axe' => 3];
        $spellPriority = ['teleport' => 1, 'fireball' => 2];
        
        // Handle weapon replacement
        if (isset($weaponPriority[$newItemType])) {
            $newItemPriority = $weaponPriority[$newItemType];
            $weapons = $inventory['weapons'] ?? [];
            
            foreach ($weapons as $weapon) {
                $weaponType = $weapon['type'] ?? '';
                $currentPriority = $weaponPriority[$weaponType] ?? 0;
                
                if ($newItemPriority > $currentPriority) {
                    return $weapon;
                }
            }
        }
        
        // Handle spell replacement
        if (isset($spellPriority[$newItemType])) {
            $newItemPriority = $spellPriority[$newItemType];
            $spells = $inventory['spells'] ?? [];
            
            foreach ($spells as $spell) {
                $spellType = $spell['type'] ?? '';
                $currentPriority = $spellPriority[$spellType] ?? 0;
                
                if ($newItemPriority > $currentPriority) {
                    return $spell;
                }
            }
        }
        
        return null;
    }
    
    // Helper method to create action entries
    private function createAction(string $type, array $details = []): array
    {
        return [
            'type' => $type,
            'timestamp' => (new \DateTimeImmutable())->format('H:i:s.v'),
            'details' => $details
        ];
    }
    
    /**
     * Apply strategy configuration based on strategy type
     * ALL strategies prioritize treasures as they are the main win condition
     */
    private function applyStrategy(string $strategyType): void
    {
        switch ($strategyType) {
            case 'aggressive':
                $this->strategyConfig = [
                    'preferTreasures' => true,  // Always prioritize treasures
                    'preferBattles' => true,
                    'riskTolerance' => 0.8,  // High risk tolerance
                    'healingThreshold' => 1,  // Only heal when critical
                    'inventoryPriority' => ['key', 'axe', 'sword', 'dagger', 'fireball'],  // Key first for chests!
                ];
                break;
                
            case 'defensive':
                $this->strategyConfig = [
                    'preferTreasures' => true,  // Always prioritize treasures
                    'preferBattles' => false,
                    'riskTolerance' => 0.3,  // Low risk tolerance
                    'healingThreshold' => 3,  // Heal early
                    'inventoryPriority' => ['key', 'sword', 'axe', 'dagger', 'fireball'],  // Key first for chests!
                ];
                break;
                
            case 'balanced':
            default:
                $this->strategyConfig = [
                    'preferTreasures' => true,  // Always prioritize treasures
                    'preferBattles' => true,
                    'riskTolerance' => 0.5,  // Medium risk tolerance
                    'healingThreshold' => 2,  // Heal at medium HP
                    'inventoryPriority' => ['key', 'sword', 'axe', 'dagger', 'fireball'],  // Key first for chests!
                ];
                break;
        }
    }
    
    /**
     * Determine the strategic goal based on current game state
     */
    private function determineStrategicGoal(Uuid $gameId, Uuid $playerId): array
    {
        $trackingKey = "{$gameId}_{$playerId}";
        $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));
        $field = $this->messageBus->dispatch(new GetField($gameId));
        $deck = $this->messageBus->dispatch(new GetDeck($gameId));
        $currentPos = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        $currentPosStr = $currentPos->toString();
        
        $playerStrength = $this->calculateEffectiveStrength($player);
        $hasKey = $this->playerHasKey($player);
        $items = $field->getItems();
        
        // Priority 0: HEALING - If HP is critical, healing is the top priority
        if ($player->getHP() <= 1) {
            return [
                'type' => 'HEAL',
                'target' => '0,0',  // Main healing fountain
                'reason' => 'Critical HP - must heal immediately',
                'priority' => 0
            ];
        }
        
        // Priority 1: If we can win the game (defeat dragon), that's the goal
        $dragonPos = null;
        $dragonHP = 0;
        foreach ($items as $pos => $item) {
            if ($item instanceof \App\Game\Item\Item && $item->name->value === 'dragon') {
                $dragonPos = $pos;
                $dragonHP = $item->guardHP;
                break;
            }
        }
        
        if ($dragonPos && $playerStrength >= $dragonHP) {
            return [
                'type' => 'WIN_GAME',
                'target' => $dragonPos,
                'reason' => "Can defeat dragon (strength {$playerStrength} >= {$dragonHP})",
                'priority' => 1
            ];
        }
        
        // Priority 2: If we have a key, find chests to open
        if ($hasKey) {
            $chests = [];
            foreach ($items as $pos => $item) {
                if ($item instanceof \App\Game\Item\Item && $item->type->value === 'chest' && !$item->guardDefeated) {
                    $chests[] = $pos;
                }
            }
            if (!empty($chests)) {
                // Find closest chest
                $closestChest = null;
                $minDistance = PHP_INT_MAX;
                foreach ($chests as $chestPos) {
                    $distance = $this->calculateManhattanDistance($currentPosStr, $chestPos);
                    if ($distance < $minDistance) {
                        $minDistance = $distance;
                        $closestChest = $chestPos;
                    }
                }
                return [
                    'type' => 'COLLECT_TREASURE',
                    'target' => $closestChest,
                    'reason' => "Have key, collecting treasure at distance {$minDistance}",
                    'priority' => 2
                ];
            }
        }
        
        // Priority 3: Get stronger to defeat dragon
        if ($dragonPos && $playerStrength < $dragonHP) {
            $neededStrength = $dragonHP - $playerStrength;
            
            // Look for weapons that would help
            $weapons = [];
            foreach ($items as $pos => $item) {
                if ($item instanceof \App\Game\Item\Item) {
                    $reward = $this->getMonsterReward($item);
                    if (in_array($reward, ['dagger', 'sword', 'axe'])) {
                        $weapons[$pos] = [
                            'type' => $reward,
                            'hp' => $item->guardHP,
                            'value' => $reward === 'dagger' ? 1 : ($reward === 'sword' ? 2 : 3)
                        ];
                    }
                }
            }
            
            if (!empty($weapons)) {
                // Find best weapon to pursue
                $bestWeapon = null;
                $bestValue = 0;
                foreach ($weapons as $pos => $weapon) {
                    if ($playerStrength >= $weapon['hp'] && $weapon['value'] > $bestValue) {
                        $bestWeapon = $pos;
                        $bestValue = $weapon['value'];
                    }
                }
                
                if ($bestWeapon) {
                    // Find closest winnable weapon
                    $closestWeapon = null;
                    $minDistance = PHP_INT_MAX;
                    foreach ($weapons as $pos => $weapon) {
                        if ($playerStrength >= $weapon['hp']) {
                            $distance = $this->calculateManhattanDistance($currentPosStr, $pos);
                            if ($distance < $minDistance || 
                                ($distance === $minDistance && $weapon['value'] > $weapons[$closestWeapon]['value'])) {
                                $minDistance = $distance;
                                $closestWeapon = $pos;
                            }
                        }
                    }
                    
                    if ($closestWeapon) {
                        return [
                            'type' => 'GET_STRONGER',
                            'target' => $closestWeapon,
                            'reason' => "Need +{$neededStrength} strength for dragon, pursuing {$weapons[$closestWeapon]['type']}",
                            'priority' => 3
                        ];
                    }
                }
            }
        }
        
        // Priority 4: Get a key if we don't have one
        if (!$hasKey) {
            $keyMonsters = [];
            foreach ($items as $pos => $item) {
                if ($item instanceof \App\Game\Item\Item) {
                    $reward = $this->getMonsterReward($item);
                    if ($reward === 'key') {
                        $keyMonsters[$pos] = [
                            'hp' => $item->guardHP,
                            'name' => $item->name->value
                        ];
                    }
                }
            }
            
            if (!empty($keyMonsters)) {
                // Find closest winnable key monster
                $closestKeyMonster = null;
                $minDistance = PHP_INT_MAX;
                foreach ($keyMonsters as $pos => $monster) {
                    if ($playerStrength >= $monster['hp']) {
                        $distance = $this->calculateManhattanDistance($currentPosStr, $pos);
                        if ($distance < $minDistance) {
                            $minDistance = $distance;
                            $closestKeyMonster = $pos;
                        }
                    }
                }
                
                if ($closestKeyMonster) {
                    return [
                        'type' => 'GET_KEY',
                        'target' => $closestKeyMonster,
                        'reason' => "Need key for chests, targeting {$keyMonsters[$closestKeyMonster]['name']}",
                        'priority' => 4
                    ];
                }
            }
        }
        
        // Priority 5: Systematic exploration
        return [
            'type' => 'EXPLORE',
            'target' => null,
            'reason' => "Exploring to find opportunities",
            'priority' => 5
        ];
    }
    
    /**
     * Choose movement target based on strategy - TREASURES FIRST!
     * @return string|null Returns the target position or null if all positions have been visited
     */
    private function chooseMovementTarget(array $moveOptions, $field, $player, Uuid $gameId, Uuid $playerId): ?string
    {
        if (empty($moveOptions)) {
            throw new \RuntimeException('No move options available');
        }
        
        // If we have a current target and it's reachable from one of our move options, prioritize moves towards it
        $trackingKey = "{$gameId}_{$playerId}";
        if (isset(self::$persistentTargets[$trackingKey]) && self::$persistentTargets[$trackingKey] !== null) {
            error_log("DEBUG AI: Have persistent target " . self::$persistentTargetReasons[$trackingKey] . " at " . self::$persistentTargets[$trackingKey] . ", finding best move towards it");
            
            $bestMoveTowardsTarget = null;
            $shortestDistance = PHP_INT_MAX;
            
            foreach ($moveOptions as $option) {
                [$moveX, $moveY] = explode(',', $option);
                [$targetX, $targetY] = explode(',', self::$persistentTargets[$trackingKey]);
                $distance = abs((int)$moveX - (int)$targetX) + abs((int)$moveY - (int)$targetY);
                
                if ($distance < $shortestDistance) {
                    $shortestDistance = $distance;
                    $bestMoveTowardsTarget = $option;
                }
            }
            
            if ($bestMoveTowardsTarget !== null) {
                error_log("DEBUG AI: Continuing towards target - moving to {$bestMoveTowardsTarget} (distance to target: {$shortestDistance})");
                return $bestMoveTowardsTarget;
            }
        }
        
        // Check if we have an exploration target that we haven't reached yet
        if (isset(self::$explorationTargets[$trackingKey]) && self::$explorationTargets[$trackingKey] !== null) {
            $explorationTarget = self::$explorationTargets[$trackingKey];
            
            // Check if we've reached our exploration target
            if (in_array($explorationTarget, $moveOptions)) {
                // We can reach it, so go there
                error_log("DEBUG AI: Continuing to exploration target at {$explorationTarget}");
                self::$explorationHistory[$trackingKey][$explorationTarget] = true;
                self::$explorationTargets[$trackingKey] = null; // Clear target once reached
                return $explorationTarget;
            }
            
            // Find best move towards exploration target
            $bestMoveTowardsExploration = null;
            $shortestDistance = PHP_INT_MAX;
            
            foreach ($moveOptions as $option) {
                [$moveX, $moveY] = explode(',', $option);
                [$targetX, $targetY] = explode(',', $explorationTarget);
                $distance = abs((int)$moveX - (int)$targetX) + abs((int)$moveY - (int)$targetY);
                
                if ($distance < $shortestDistance) {
                    $shortestDistance = $distance;
                    $bestMoveTowardsExploration = $option;
                }
            }
            
            if ($bestMoveTowardsExploration !== null) {
                error_log("DEBUG AI: Moving towards exploration target - to {$bestMoveTowardsExploration} (distance to target: {$shortestDistance})");
                return $bestMoveTowardsExploration;
            }
        }
        
        // Filter out visited positions to prevent oscillation within a turn
        $unvisitedOptions = array_filter($moveOptions, function($option) {
            return !isset($this->visitedPositions[$option]);
        });
        
        // Also filter out positions explored in recent turns to avoid loops
        $unexploredOptions = array_filter($unvisitedOptions, function($option) use ($trackingKey) {
            return !isset(self::$explorationHistory[$trackingKey][$option]);
        });
        
        // If we have unexplored options, use those; otherwise fall back to unvisited this turn
        if (!empty($unexploredOptions)) {
            $unvisitedOptions = $unexploredOptions;
            error_log("DEBUG AI: Found " . count($unexploredOptions) . " unexplored positions to choose from");
        } else if (empty($unvisitedOptions)) {
            error_log("DEBUG AI: All reachable positions have been visited - checking if we should clear exploration history");
            
            // If we've explored everything multiple times, clear the history to allow re-exploration
            $totalExplored = count(self::$explorationHistory[$trackingKey] ?? []);
            if ($totalExplored >= 10) {  // Arbitrary limit to prevent getting stuck
                error_log("DEBUG AI: Clearing exploration history after exploring {$totalExplored} positions");
                self::$explorationHistory[$trackingKey] = [];
                
                // Try again with cleared history
                $unvisitedOptions = array_filter($moveOptions, function($option) {
                    return !isset($this->visitedPositions[$option]);
                });
            }
            
            if (empty($unvisitedOptions)) {
                // Return null to indicate no valid move available
                // The caller should handle this by either placing tiles or ending turn
                return null;
            }
        }
        
        // PRIORITY 1: Look for treasure locations (chests)
        $treasurePosition = $this->findTreasurePosition($unvisitedOptions, $field, $player);
        if ($treasurePosition !== null) {
            error_log("DEBUG AI Reasoning: Found treasure at {$treasurePosition} and I have a key - moving to collect it!");
            return $treasurePosition;
        }
        
        // PRIORITY 2: Look for weapon upgrades we can obtain
        $upgradePosition = $this->findWeaponUpgradePosition($unvisitedOptions, $field, $player);
        if ($upgradePosition !== null) {
            error_log("DEBUG AI Reasoning: Found weapon upgrade opportunity at {$upgradePosition}");
            return $upgradePosition;
        }
        
        // PRIORITY 3: Look for winnable battles with rewards
        $battlePosition = $this->findWinnableBattlePosition($unvisitedOptions, $field, $player);
        if ($battlePosition !== null && ($this->strategyConfig['preferBattles'] ?? true)) {
            $playerStrength = $this->calculateEffectiveStrength($player);
            error_log("DEBUG AI Reasoning: Found winnable battle at {$battlePosition} (our strength: {$playerStrength})");
            return $battlePosition;
        }
        
        // PRIORITY 3.5: Check for locked monsters (like dragon) that we need to move adjacent to
        $adjacentMonsterPosition = $this->findPositionAdjacentToWinnableMonster($unvisitedOptions, $field, $player, $gameId, $playerId);
        if ($adjacentMonsterPosition !== null) {
            $playerStrength = $this->calculateEffectiveStrength($player);
            error_log("DEBUG AI: Moving to {$adjacentMonsterPosition} to attack nearby locked monster (strength: {$playerStrength})");
            return $adjacentMonsterPosition;
        }
        
        // PRIORITY 4: Check for healing fountain ONLY if HP is low
        // Only move to healing fountain if HP is below threshold
        $currentHP = $player->getHP();
        $healingThreshold = $this->strategyConfig['healingThreshold'] ?? 2;
        
        // Only consider healing fountain if HP is below threshold
        if ($currentHP <= $healingThreshold) {
            foreach ($unvisitedOptions as $position) {
                // Check if this position has a healing fountain
                $placedTiles = $field->getPlacedTiles();
                if (isset($placedTiles[$position])) {
                    $tileId = $placedTiles[$position];
                    if (is_string($tileId)) {
                        $tileId = Uuid::fromString($tileId);
                    }
                    if ($tileId instanceof Uuid) {
                        $tile = $field->getTileFromCache($tileId);
                        if ($tile) {
                            $features = $tile->getFeatures();
                            foreach ($features as $feature) {
                                $featureString = '';
                                if ($feature instanceof \App\Game\Field\TileFeature) {
                                    $featureString = $feature->value;
                                } elseif (is_string($feature)) {
                                    $featureString = $feature;
                                }
                                if ($featureString === 'healing_fountain' || $featureString === TileFeature::HEALING_FOUNTAIN->value) {
                                    error_log("DEBUG AI Reasoning: Low HP ({$currentHP}), moving to healing fountain at {$position}");
                                    return $position;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // PRIORITY 5: Defensive movement when weak
        if (!($this->strategyConfig['preferBattles'] ?? true) && $player->getHP() <= 2) {
            // Pick safest option (furthest from center for now)
            // Re-index array to ensure sequential keys
            $moveOptionsIndexed = array_values($unvisitedOptions);
            $safePos = $moveOptionsIndexed[count($moveOptionsIndexed) - 1];
            error_log("DEBUG AI Reasoning: Low HP ({$player->getHP()}), moving defensively to {$safePos}");
            return $safePos;
        }
        
        // Default: pick based on strategy preference and set exploration target
        // Find the farthest unexplored position to set as an exploration goal
        $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        [$currentX, $currentY] = explode(',', $currentPosition->toString());
        
        // If player doesn't have a key, exclude chest positions from exploration
        $hasKey = $this->playerHasKey($player);
        if (!$hasKey) {
            $unvisitedOptions = array_filter($unvisitedOptions, function($option) use ($field) {
                // Skip positions with chests if we don't have a key
                return !$this->positionHasChest($option, $field);
            });
            
            // If all remaining options are chests and we have no key, we need to find a key first
            if (empty($unvisitedOptions)) {
                error_log("DEBUG AI: All unvisited positions have chests but we have no key - need to find a key first");
                // Return the first move option anyway to continue exploring
                return array_values($moveOptions)[0] ?? null;
            }
        }
        
        // PRIORITY: Move to positions that are on the edge of explored area
        // These positions are more likely to allow placing new tiles
        $edgePositions = $this->findEdgePositions($unvisitedOptions, $field);
        if (!empty($edgePositions)) {
            // Pick the closest edge position to move towards
            $closestEdge = null;
            $minDistance = PHP_INT_MAX;
            foreach ($edgePositions as $edgePos) {
                [$edgeX, $edgeY] = explode(',', $edgePos);
                $distance = abs((int)$edgeX - $currentX) + abs((int)$edgeY - $currentY);
                if ($distance < $minDistance) {
                    $minDistance = $distance;
                    $closestEdge = $edgePos;
                }
            }
            if ($closestEdge !== null) {
                error_log("DEBUG AI: Moving to edge position {$closestEdge} to explore and potentially place new tiles");
                return $closestEdge;
            }
        }
        
        $farthestOption = null;
        $maxDistance = 0;
        
        // Find the farthest unexplored tile as our exploration target
        foreach ($unvisitedOptions as $option) {
            [$optionX, $optionY] = explode(',', $option);
            $distance = abs((int)$optionX - (int)$currentX) + abs((int)$optionY - (int)$currentY);
            
            if ($distance > $maxDistance) {
                $maxDistance = $distance;
                $farthestOption = $option;
            }
        }
        
        // Set the farthest position as our exploration target to ensure we explore systematically
        if ($farthestOption !== null && !isset(self::$explorationTargets[$trackingKey])) {
            self::$explorationTargets[$trackingKey] = $farthestOption;
            error_log("DEBUG AI: Set exploration target to farthest unexplored position at {$farthestOption}");
        }
        
        if ($this->strategyConfig['preferBattles'] ?? false) {
            // Re-index array to ensure sequential keys
            $moveOptionsIndexed = array_values($unvisitedOptions);
            $selectedPos = $moveOptionsIndexed[0];  // Aggressive: explore actively
            
            // Mark this position as part of exploration history
            self::$explorationHistory[$trackingKey][$selectedPos] = true;
            
            error_log("DEBUG AI Reasoning: Aggressive strategy - exploring new area at {$selectedPos}");
            return $selectedPos;
        }
        
        // Balanced: pick middle option from unvisited positions
        // Re-index array to ensure sequential keys
        $moveOptionsIndexed = array_values($unvisitedOptions);
        $middleIndex = intval(count($moveOptionsIndexed) / 2);
        $balancedPos = $moveOptionsIndexed[$middleIndex] ?? $moveOptionsIndexed[0];
        
        // Mark this position as part of exploration history
        self::$explorationHistory[$trackingKey][$balancedPos] = true;
        
        error_log("DEBUG AI Reasoning: Balanced exploration - moving to {$balancedPos}");
        return $balancedPos;
    }
    
    /**
     * Execute movement based on strategic goal
     */
    private function executeGoalOrientedMovement(
        Uuid $gameId,
        Uuid $playerId,
        Uuid $currentTurnId,
        FieldPlace $currentPosition,
        array $moveToOptions,
        array $strategicGoal,
        array &$actions
    ): void {
        $trackingKey = "{$gameId}_{$playerId}";
        $currentPosStr = $currentPosition->toString();
        
        // Track previous position to prevent oscillation
        $previousPos = self::$previousPosition[$trackingKey] ?? null;
        
        // If we have a specific target, move towards it
        if ($strategicGoal['target'] !== null) {
            $field = $this->messageBus->dispatch(new GetField($gameId));
            
            // For HEAL goal, we may not have a direct path but should move towards healing fountain
            if ($strategicGoal['type'] === 'HEAL') {
                // Find the move that gets us closest to the healing fountain
                $bestMove = null;
                $minDistance = PHP_INT_MAX;
                foreach ($moveToOptions as $moveOption) {
                    $distance = $this->calculateManhattanDistance($moveOption, $strategicGoal['target']);
                    if ($distance < $minDistance) {
                        $minDistance = $distance;
                        $bestMove = $moveOption;
                    }
                }
                
                if ($bestMove !== null) {
                    [$toX, $toY] = explode(',', $bestMove);
                    [$fromX, $fromY] = explode(',', $currentPosStr);
                    
                    $actions[] = $this->createAction('heal_goal_movement', [
                        'goal' => 'HEAL',
                        'target' => $strategicGoal['target'],
                        'next_step' => $bestMove,
                        'distance' => $minDistance
                    ]);
                    
                    // Update previous position before moving
                    self::$previousPosition[$trackingKey] = $currentPosStr;
                    
                    $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                    $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                    $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                    return;
                }
            }
            
            $path = $this->findPathToTarget($currentPosStr, $strategicGoal['target'], $field->getPlacedTiles(), $field);
            
            if (!empty($path) && count($path) > 1) {
                $nextStep = $path[1]; // First step is current position
                
                // Check if next step would take us back to previous position (oscillation)
                if ($previousPos !== null && $nextStep === $previousPos && $strategicGoal['type'] === 'EXPLORE') {
                    // For exploration, avoid going back. Choose a different direction
                    $actions[] = $this->createAction('anti_oscillation', [
                        'message' => 'Avoiding oscillation, choosing different direction',
                        'avoided' => $nextStep,
                        'previous' => $previousPos
                    ]);
                    
                    // Filter out the previous position from options
                    $filteredOptions = array_filter($moveToOptions, fn($opt) => $opt !== $previousPos);
                    if (!empty($filteredOptions)) {
                        $moveToOptions = array_values($filteredOptions);
                    }
                    // Fall through to exploration logic below
                } else {
                    // Check if next step is in available moves
                    $canMove = false;
                    foreach ($moveToOptions as $moveOption) {
                        if ($moveOption === $nextStep) {
                            $canMove = true;
                            break;
                        }
                    }
                    
                    if ($canMove) {
                        [$toX, $toY] = explode(',', $nextStep);
                        [$fromX, $fromY] = explode(',', $currentPosStr);
                        
                        $actions[] = $this->createAction('goal_movement', [
                            'goal' => $strategicGoal['type'],
                            'target' => $strategicGoal['target'],
                            'next_step' => $nextStep
                        ]);
                        
                        // Update previous position before moving
                        self::$previousPosition[$trackingKey] = $currentPosStr;
                        
                        $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                        $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                        $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                        return;
                    }
                }
            }
        }
        
        // EXPLORE: Smart exploration without oscillation
        if ($strategicGoal['type'] === 'EXPLORE' || $strategicGoal['target'] === null) {
            // Track exploration history
            if (!isset(self::$explorationHistory[$trackingKey])) {
                self::$explorationHistory[$trackingKey] = [];
            }
            
            $explorationHistory = &self::$explorationHistory[$trackingKey];
            
            // Score each move option based on exploration value
            $moveScores = [];
            foreach ($moveToOptions as $moveOption) {
                $score = 100; // Base score
                
                // Penalize returning to previous position heavily
                if ($previousPos !== null && $moveOption === $previousPos) {
                    $score -= 80;
                }
                
                // Penalize recently visited positions
                $visitCount = isset($explorationHistory[$moveOption]) ? 1 : 0;
                $score -= $visitCount * 20;
                
                // Bonus for unexplored areas
                if ($visitCount === 0) {
                    $score += 50;
                }
                
                $moveScores[$moveOption] = $score;
            }
            
            // Sort by score (descending)
            arsort($moveScores);
            
            // Choose the best scoring move
            if (!empty($moveScores)) {
                $bestMove = array_key_first($moveScores);
                $bestScore = $moveScores[$bestMove];
                
                $actions[] = $this->createAction('exploration_choice', [
                    'chosen' => $bestMove,
                    'score' => $bestScore,
                    'all_scores' => $moveScores
                ]);
                
                // Update exploration history
                $explorationHistory[$bestMove] = true;
                
                // Update previous position
                self::$previousPosition[$trackingKey] = $currentPosStr;
                
                [$toX, $toY] = explode(',', $bestMove);
                [$fromX, $fromY] = explode(',', $currentPosStr);
                
                $moveResult = $this->apiClient->movePlayer($gameId, $playerId, $currentTurnId, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, false);
                $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
                $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
                return;
            }
        }
        
        // Fallback to original movement logic if nothing else works
        $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
    }
    
    /**
     * Choose tile placement based on strategy
     */
    private function chooseTilePlacement(array $placeOptions, $field, $player): string
    {
        if (empty($placeOptions)) {
            throw new \RuntimeException('No placement options available');
        }
        
        // For now, use simple placement strategy
        // TODO: Analyze field to pick optimal placement
        
        // Aggressive: expand quickly (pick furthest option)
        if ($this->strategyConfig['preferBattles'] ?? false) {
            return $placeOptions[count($placeOptions) - 1] ?? $placeOptions[0];
        }
        
        // Defensive: stay compact (pick closest option)
        if (!($this->strategyConfig['preferBattles'] ?? true)) {
            return $placeOptions[0];
        }
        
        // Balanced: pick first available
        return $placeOptions[0];
    }
    
    /**
     * Calculate effective combat strength based on strategy
     */
    private function calculateEffectiveStrength($player): int
    {
        $baseHP = $player->getHP();
        $inventory = $player->getInventory();
        
        // Calculate expected damage from dice rolls
        // Players always roll 2 d6 dice in combat
        // Average per die = 3.5, so 2 dice average = 7
        // For AI decision making, use the average expected value
        $expectedDiceRoll = 7; // 2 dice * 3.5 average
        
        // Start with expected dice damage
        $totalStrength = $expectedDiceRoll;
        
        // Add weapon bonuses - inventory structure is ['weapon' => [...], 'spell' => [...], etc]
        foreach ($inventory['weapon'] ?? [] as $weapon) {
            // Handle both array and Item object formats
            if ($weapon instanceof \App\Game\Item\Item) {
                $weaponType = $weapon->type->value ?? '';
            } else {
                $weaponType = $weapon['type'] ?? '';
            }
            
            $damage = match($weaponType) {
                'dagger' => 1,
                'sword' => 2,
                'axe' => 3,
                default => 0
            };
            $totalStrength += $damage;
        }
        
        // Add consumable spell bonuses if aggressive
        if ($this->strategyConfig['preferBattles'] ?? false) {
            foreach ($inventory['spell'] ?? [] as $spell) {
                // Handle both array and Item object formats
                if ($spell instanceof \App\Game\Item\Item) {
                    $spellType = $spell->type->value ?? '';
                    // For Item objects, spells are always "active" (uses doesn't apply)
                    if ($spellType === 'fireball') {
                        $totalStrength += 1;
                    }
                } else {
                    if ($spell['type'] === 'fireball' && ($spell['uses'] ?? 0) > 0) {
                        $totalStrength += 1;
                    }
                }
            }
        }
        
        return $totalStrength;
    }
    
    /**
     * Get all monsters on the field with their positions and details
     */
    private function getMonstersOnField($field, $player): array
    {
        $monsters = [];
        $items = $field->getItems();
        
        foreach ($items as $position => $item) {
            // Check if it's a monster (has HP and not defeated)
            if ($item instanceof \App\Game\Item\Item) {
                if (!$item->guardDefeated && $item->guardHP > 0) {
                    $monsterType = $item->type->value ?? '';
                    $monsterName = $monsterType;
                    
                    // Get the reward this monster drops
                    $reward = null;
                    if (in_array($monsterType, ['skeleton_turnkey', 'skeleton_warrior', 'skeleton_king'])) {
                        $reward = match($monsterType) {
                            'skeleton_turnkey' => 'key',
                            'skeleton_warrior' => 'sword',
                            'skeleton_king' => 'axe',
                            default => null
                        };
                    } else if (in_array($monsterType, ['giant_rat', 'mummy'])) {
                        $reward = match($monsterType) {
                            'giant_rat' => 'dagger',
                            'mummy' => 'fireball',
                            default => null
                        };
                    }
                    
                    $monsters[] = [
                        'position' => $position,
                        'name' => $monsterName,
                        'type' => $reward ?? $monsterType,
                        'hp' => $item->guardHP
                    ];
                }
            } else if (is_array($item)) {
                // Handle array format
                if (isset($item['monster']) && !empty($item['monster']['hp'])) {
                    $monsterName = $item['monster']['name'] ?? 'unknown';
                    $monsterType = $item['monster']['type'] ?? $monsterName;
                    $monsterHP = (int)$item['monster']['hp'];
                    
                    // Get the reward
                    $reward = $item['monster']['reward'] ?? null;
                    if (!$reward) {
                        // Infer reward from monster type
                        $reward = match($monsterType) {
                            'skeleton_turnkey' => 'key',
                            'skeleton_warrior' => 'sword',
                            'skeleton_king' => 'axe',
                            'giant_rat' => 'dagger',
                            'mummy' => 'fireball',
                            default => null
                        };
                    }
                    
                    $monsters[] = [
                        'position' => $position,
                        'name' => $monsterName,
                        'type' => $reward ?? $monsterType,
                        'hp' => $monsterHP
                    ];
                }
            }
        }
        
        return $monsters;
    }
    
    /**
     * Check if we should move to collect treasure (chest with key)
     */
    private function shouldMoveToTreasure($player, $field, array $moveOptions): bool
    {
        // Check if player has a key
        $hasKey = $this->playerHasKey($player);
        if (!$hasKey) {
            return false;
        }
        
        // Debug: Log that we have a key and are checking for chests
        error_log('DEBUG AI: Player has key, checking ' . count($moveOptions) . ' move options for chests');
        
        // Check if any move option has a chest
        foreach ($moveOptions as $position) {
            if ($this->positionHasChest($position, $field)) {
                error_log('DEBUG AI: Found chest at position ' . $position . '! Should move to it.');
                return true;  // Found a chest and we have a key!
            }
        }
        
        error_log('DEBUG AI: No chests found in move options');
        return false;
    }
    
    /**
     * Find position with treasure (chest) if player has key
     */
    private function findTreasurePosition(array $moveOptions, $field, $player): ?string
    {
        $hasKey = $this->playerHasKey($player);
        if (!$hasKey) {
            return null;
        }
        
        // Look for chest positions
        foreach ($moveOptions as $position) {
            if ($this->positionHasChest($position, $field)) {
                return $position;  // Return first chest position found
            }
        }
        
        return null;
    }
    
    /**
     * Find position with weapon upgrade opportunity
     */
    private function findWeaponUpgradePosition(array $moveOptions, $field, $player): ?string
    {
        $inventory = $player->getInventory();
        
        // Get current weapon priorities - inventory structure is ['weapon' => [...], ...]
        $weaponPriority = ['dagger' => 1, 'sword' => 2, 'axe' => 3];
        $currentWeapons = [];
        $weapons = isset($inventory['weapon']) ? $inventory['weapon'] : [];
        
        foreach ($weapons as $weapon) {
            // Handle both array and Item object formats
            if ($weapon instanceof \App\Game\Item\Item) {
                $currentWeapons[] = $weapon->type->value ?? '';
            } else {
                $currentWeapons[] = $weapon['type'] ?? '';
            }
        }
        
        // Find worst weapon priority (lowest value)
        $worstWeaponPriority = 999;
        foreach ($currentWeapons as $weapon) {
            $priority = $weaponPriority[$weapon] ?? 0;
            if ($priority < $worstWeaponPriority) {
                $worstWeaponPriority = $priority;
            }
        }
        
        // If inventory isn't full, any weapon is worth getting (unless we already have 2 axes)
        if (count($currentWeapons) < 2) {
            // But check if we already have axes
            $axeCount = count(array_filter($currentWeapons, fn($w) => $w === 'axe'));
            if ($axeCount >= 1) {
                // We have at least one axe, only get another axe
                $worstWeaponPriority = 2; // Only look for axes (priority 3)
            } else {
                $worstWeaponPriority = 0; // Accept any weapon
            }
        }
        
        $playerStrength = $this->calculateEffectiveStrength($player);
        $items = $field->getItems();
        
        // Look for the best upgrade opportunity
        $bestUpgradePosition = null;
        $bestUpgradePriority = $worstWeaponPriority;
        
        foreach ($moveOptions as $position) {
            if (isset($items[$position])) {
                $item = $items[$position];
                $monsterReward = $this->getMonsterReward($item);
                
                if ($monsterReward && in_array($monsterReward, ['dagger', 'sword', 'axe'])) {
                    $rewardPriority = $weaponPriority[$monsterReward] ?? 0;
                    
                    // Check if this is better than what we have
                    if ($rewardPriority > $worstWeaponPriority && $rewardPriority > $bestUpgradePriority) {
                        $monsterHP = $this->getMonsterHP($item);
                        if ($monsterHP > 0 && $playerStrength >= $monsterHP) {
                            $bestUpgradePosition = $position;
                            $bestUpgradePriority = $rewardPriority;
                        }
                    }
                }
            }
        }
        
        return $bestUpgradePosition;
    }
    
    /**
     * Find position with winnable battle
     */
    private function findWinnableBattlePosition(array $moveOptions, $field, $player): ?string
    {
        $playerStrength = $this->calculateEffectiveStrength($player);
        
        foreach ($moveOptions as $position) {
            $items = $field->getItems();
            if (isset($items[$position])) {
                $item = $items[$position];
                
                // Handle both Item objects and arrays
                if ($item instanceof \App\Game\Item\Item) {
                    // Check if there's a monster we can defeat
                    if ($item->guardHP > 0 && !$item->guardDefeated) {
                        if ($playerStrength >= $item->guardHP) {
                            return $position;  // Found winnable battle
                        }
                    }
                } elseif (is_array($item)) {
                    // Check if there's a monster we can defeat (array format)
                    if (isset($item['monster']) && $item['monster'] !== null) {
                        $monsterHP = $item['monster']['hp'] ?? 999;
                        if ($playerStrength >= $monsterHP) {
                            return $position;  // Found winnable battle
                        }
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Check if an item is worth attacking a monster for
     */
    private function isItemWorthAttackingFor(string $itemType, $player): bool
    {
        // Get current inventory
        $inventory = $player->getInventory();
        
        // Always worth attacking for keys and fireballs
        if (in_array($itemType, ['key', 'fireball'])) {
            return true;
        }
        
        // For weapons, check if it's an upgrade
        if (in_array($itemType, ['axe', 'sword', 'dagger'])) {
            $newWeaponValue = $this->getWeaponValue($itemType);
            
            // Get our current weapons and their values
            $currentWeapons = $this->getWeaponsInInventory($inventory);
            $weaponCount = count($currentWeapons);
            
            // If we have less than 2 weapons, always worth getting more
            if ($weaponCount < 2) {
                return true;
            }
            
            // If we have 2 weapons, check if new weapon is better than our worst
            if ($weaponCount >= 2) {
                $worstWeaponValue = $this->getWorstWeaponValue($inventory);
                if ($newWeaponValue > $worstWeaponValue) {
                    return true; // Worth replacing worst weapon
                }
                
                // Also worth getting if it's the same as our best (e.g., 2 swords better than sword + dagger)
                $bestWeaponValue = $this->getBestWeaponValue($inventory);
                if ($newWeaponValue == $bestWeaponValue && $worstWeaponValue < $bestWeaponValue) {
                    return true; // e.g., getting 2nd sword when we have sword + dagger
                }
            }
        }
        
        return false;
    }
    
    /**
     * Choose the best monster to attack based on item value
     */
    private function chooseBestMonsterTarget(array $monsters, $player): ?array
    {
        $bestMonster = null;
        $bestValue = 0;
        
        foreach ($monsters as $monster) {
            $value = $this->getItemValueForCombat($monster['type']);
            
            // Consider our ability to defeat the monster
            $canDefeat = $this->estimateVictoryChance($player, $monster['hp']);
            
            // Prioritize by value and chance of victory
            $score = $value * $canDefeat;
            
            if ($score > $bestValue) {
                $bestValue = $score;
                $bestMonster = $monster;
            }
        }
        
        return $bestMonster;
    }
    
    /**
     * Execute movement towards a distant monster
     */
    private function executeMoveTowardsMonster(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, $currentPosition, array $moveToOptions, array $targetMonster, array &$actions): void
    {
        $targetPos = $targetMonster['position'];
        $trackingKey = "{$gameId}_{$playerId}";
        
        // Get field info for pathfinding
        $field = $this->messageBus->dispatch(new GetField($gameId));
        $placedTiles = $field->getPlacedTiles();
        
        // Check if we have a stored path for this monster target
        $needNewPath = false;
        if (!isset(self::$persistentMonsterTargets[$trackingKey]) || 
            self::$persistentMonsterTargets[$trackingKey]['position'] !== $targetPos ||
            empty(self::$persistentMonsterTargets[$trackingKey]['path'])) {
            $needNewPath = true;
        } else {
            // Check if the first step of the stored path is reachable from current position
            $storedPath = self::$persistentMonsterTargets[$trackingKey]['path'];
            $firstStep = $storedPath[0] ?? null;
            
            // Get valid transitions from current position
            $currentPlace = FieldPlace::fromString($currentPosition->toString());
            $transitions = $field->getTransitionsFrom($currentPlace);
            
            // If first step isn't directly reachable, we need a new path
            if ($firstStep && !in_array($firstStep, $transitions)) {
                error_log("DEBUG AI: Stored path first step {$firstStep} not reachable from {$currentPosition->toString()}, replanning");
                $needNewPath = true;
            }
        }
        
        if ($needNewPath) {
            
            // Plan a new path to the monster using BFS
            error_log("DEBUG AI: Planning path to monster {$targetMonster['name']} at {$targetPos} from {$currentPosition->toString()}");
            $path = $this->findPathToTarget($currentPosition->toString(), $targetPos, $placedTiles, $field);
            
            if (!empty($path)) {
                self::$persistentMonsterTargets[$trackingKey] = [
                    'position' => $targetPos,
                    'path' => $path,
                    'monster' => $targetMonster
                ];
                error_log("DEBUG AI: Successfully planned path to monster with " . count($path) . " steps: " . implode(' -> ', $path));
            } else {
                error_log("DEBUG AI: No path found to monster at {$targetPos}");
                // No path exists, return to try other strategies
                $actions[] = $this->createAction('ai_info', ['message' => "No path to monster at {$targetPos}, trying other options"]);
                unset(self::$persistentMonsterTargets[$trackingKey]);
                return;
            }
        }
        
        // Get the next step from the planned path
        $monsterPath = &self::$persistentMonsterTargets[$trackingKey]['path'];
        $nextStep = null;
        
        // Find the next unvisited step in the path
        while (!empty($monsterPath)) {
            $candidateStep = array_shift($monsterPath);
            if (!isset($this->visitedPositions[$candidateStep])) {
                $nextStep = $candidateStep;
                break;
            } else {
                error_log("DEBUG AI: Skipping already visited position {$candidateStep} in path");
            }
        }
        
        // If no valid next step found, replan
        if ($nextStep === null) {
            error_log("DEBUG AI: All path steps visited or path empty, replanning...");
            $path = $this->findPathToTarget($currentPosition->toString(), $targetPos, $placedTiles, $field);
            
            if (!empty($path)) {
                // Filter out visited positions from the new path
                $unvisitedPath = array_filter($path, fn($step) => !isset($this->visitedPositions[$step]));
                
                if (!empty($unvisitedPath)) {
                    $nextStep = array_shift($unvisitedPath);
                    self::$persistentMonsterTargets[$trackingKey]['path'] = array_values($unvisitedPath);
                    error_log("DEBUG AI: Replanned path with " . count($unvisitedPath) . " unvisited steps");
                } else {
                    error_log("DEBUG AI: All positions in new path already visited, cannot continue pursuit");
                    $actions[] = $this->createAction('ai_info', ['message' => 'All path positions visited, trying other options']);
                    unset(self::$persistentMonsterTargets[$trackingKey]);
                    return;
                }
            } else {
                error_log("DEBUG AI: No path available to monster after replan");
                $actions[] = $this->createAction('ai_info', ['message' => 'No path to monster available, trying other options']);
                unset(self::$persistentMonsterTargets[$trackingKey]);
                return;
            }
        }
        
        // Verify the next step is in our available moves
        if (!in_array($nextStep, $moveToOptions)) {
            error_log("DEBUG AI: Next path step {$nextStep} not in available moves, abandoning pursuit");
            // Clear the stored path and try other strategies
            unset(self::$persistentMonsterTargets[$trackingKey]);
            $actions[] = $this->createAction('ai_info', ['message' => 'Path step not in available moves, trying other options']);
            return;
        }
        
        // Execute the move
        $actions[] = $this->createAction('ai_reasoning', [
            'message' => "Moving to {$nextStep} following path to {$targetMonster['name']} for {$targetMonster['type']}"
        ]);
        
        [$toX, $toY] = explode(',', $nextStep);
        $moveResult = $this->apiClient->movePlayer(
            $gameId,
            $playerId,
            $currentTurnId,
            $currentPosition->positionX,
            $currentPosition->positionY,
            (int)$toX,
            (int)$toY,
            false
        );
        
        $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
        
        if ($moveResult['success']) {
            // Track this position as visited
            $this->visitedPositions[$nextStep] = true;
            $this->moveCount++;
            
            // Handle any battle or item that might occur during movement
            $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
        } else {
            error_log("DEBUG AI: Move to {$nextStep} failed: " . json_encode($moveResult));
            // Clear the stored path on failure
            unset(self::$persistentMonsterTargets[$trackingKey]);
        }
    }
    
    /**
     * Execute attack on a monster
     */
    private function executeAttackMonster(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, $currentPosition, array $targetMonster, array &$actions): void
    {
        $targetPos = $targetMonster['position'];
        
        $actions[] = $this->createAction('ai_decision', [
            'decision' => 'Attacking monster',
            'target' => $targetPos,
            'monster' => $targetMonster['name'],
            'reward' => $targetMonster['type']
        ]);
        
        // Move to the monster's position
        $moveResult = $this->apiClient->movePlayer(
            $gameId,
            $playerId,
            $currentTurnId,
            $currentPosition->positionX,
            $currentPosition->positionY,
            (int)explode(',', $targetPos)[0],
            (int)explode(',', $targetPos)[1],
            false
        );
        
        $actions[] = $this->createAction('move_player', ['result' => $moveResult]);
        
        // Track this position as visited
        $this->visitedPositions[$targetPos] = true;
        $this->moveCount++;
        
        // Handle battle result if it occurred
        if ($moveResult['success'] && isset($moveResult['response']['battleInfo'])) {
            $battleInfo = $moveResult['response']['battleInfo'];
            $actions[] = $this->createAction('battle_detected', ['battleResult' => $battleInfo['result'] ?? 'unknown']);
            
            if ($battleInfo['result'] === 'win') {
                $this->handleBattleWin($gameId, $playerId, $currentTurnId, $moveResult['response'], $actions);
            } else {
                $this->handleBattleLossOrDraw($gameId, $playerId, $currentTurnId, $battleInfo, $actions);
            }
        }
    }
    
    /**
     * Get the value of the best weapon in inventory
     */
    private function getBestWeaponValue(array $inventory): int
    {
        $bestValue = 0;
        
        // Handle both formats: categorized array or flat array of Item objects
        if (isset($inventory['weapon']) && is_array($inventory['weapon'])) {
            // Categorized format
            foreach ($inventory['weapon'] as $item) {
                $itemType = '';
                if (is_array($item) && isset($item['type'])) {
                    $itemType = $item['type'];
                } elseif (is_object($item) && property_exists($item, 'type')) {
                    $itemType = $item->type->value ?? '';
                }
                
                if ($itemType) {
                    $value = $this->getWeaponValue($itemType);
                    if ($value > $bestValue) {
                        $bestValue = $value;
                    }
                }
            }
        } else {
            // Flat array of Item objects
            foreach ($inventory as $item) {
                if (is_object($item) && property_exists($item, 'type')) {
                    $itemType = $item->type->value ?? '';
                    if (in_array($itemType, ['axe', 'sword', 'dagger'])) {
                        $value = $this->getWeaponValue($itemType);
                        if ($value > $bestValue) {
                            $bestValue = $value;
                        }
                    }
                }
            }
        }
        
        return $bestValue;
    }
    
    /**
     * Get weapon value for comparison
     */
    private function getWeaponValue(string $type): int
    {
        return match($type) {
            'axe' => 3,
            'sword' => 2,
            'dagger' => 1,
            default => 0
        };
    }
    
    /**
     * Get item value for combat decisions
     */
    private function getItemValueForCombat(string $type): int
    {
        return match($type) {
            'axe' => 5,
            'sword' => 4,
            'dagger' => 3,
            'fireball' => 2,
            'key' => 1,
            default => 0
        };
    }
    
    /**
     * Get weapons in inventory
     */
    private function getWeaponsInInventory(array $inventory): array
    {
        $weapons = [];
        
        // Handle both formats: categorized array or flat array of Item objects
        if (isset($inventory['weapon']) && is_array($inventory['weapon'])) {
            // Categorized format
            $weapons = $inventory['weapon'];
        } else {
            // Flat array of Item objects
            foreach ($inventory as $item) {
                if (is_object($item) && property_exists($item, 'type')) {
                    $itemType = $item->type->value ?? '';
                    if (in_array($itemType, ['axe', 'sword', 'dagger'])) {
                        $weapons[] = $item;
                    }
                }
            }
        }
        
        return $weapons;
    }
    
    /**
     * Count weapons in inventory
     */
    private function countWeaponsInInventory(array $inventory): int
    {
        return count($this->getWeaponsInInventory($inventory));
    }
    
    /**
     * Get the value of the worst weapon in inventory
     */
    private function getWorstWeaponValue(array $inventory): int
    {
        $worstValue = PHP_INT_MAX;
        $weapons = $this->getWeaponsInInventory($inventory);
        
        foreach ($weapons as $weapon) {
            $itemType = '';
            if (is_array($weapon) && isset($weapon['type'])) {
                $itemType = $weapon['type'];
            } elseif (is_object($weapon) && property_exists($weapon, 'type')) {
                $itemType = $weapon->type->value ?? '';
            }
            
            if ($itemType) {
                $value = $this->getWeaponValue($itemType);
                if ($value < $worstValue) {
                    $worstValue = $value;
                }
            }
        }
        
        // If no weapons found, return 0
        return $worstValue === PHP_INT_MAX ? 0 : $worstValue;
    }
    
    /**
     * Estimate chance of victory against a monster
     */
    private function estimateVictoryChance($player, int $monsterHp): float
    {
        $playerHp = $player->getHP();
        $inventory = $player->getInventory();
        
        // Base damage estimate (average dice roll)
        $avgDamage = 7; // Average of 2d6
        
        // Add weapon bonuses
        $weaponBonus = $this->getBestWeaponValue($inventory);
        $totalDamage = $avgDamage + $weaponBonus;
        
        // Check for fireballs
        $fireballCount = 0;
        
        // Handle both formats: categorized array or flat array of Item objects
        if (isset($inventory['spell']) && is_array($inventory['spell'])) {
            // Categorized format
            foreach ($inventory['spell'] as $item) {
                $isFireball = false;
                if (is_array($item) && isset($item['type']) && $item['type'] === 'fireball') {
                    $isFireball = true;
                } elseif (is_object($item) && property_exists($item, 'type') && ($item->type->value ?? '') === 'fireball') {
                    $isFireball = true;
                }
                if ($isFireball) {
                    $fireballCount++;
                }
            }
        } else {
            // Flat array of Item objects
            foreach ($inventory as $item) {
                if (is_object($item) && property_exists($item, 'type')) {
                    $itemType = $item->type->value ?? '';
                    if ($itemType === 'fireball') {
                        $fireballCount++;
                    }
                }
            }
        }
        
        // Add fireball damage if available (each fireball adds 1 damage)
        if ($fireballCount > 0) {
            $totalDamage += $fireballCount * 1; // Each fireball adds 1 damage
        }
        
        // Calculate victory chance
        if ($totalDamage >= $monsterHp) {
            return 1.0; // Guaranteed victory
        } else if ($totalDamage + 3 >= $monsterHp) {
            return 0.7; // Good chance with slightly better roll
        } else if ($totalDamage + 2 >= $monsterHp) {
            return 0.5; // Decent chance with good roll
        } else if ($fireballCount > 0 && $totalDamage >= $monsterHp - 2) {
            return 0.3; // Small chance with consumables
        } else {
            return 0.1; // Very low chance
        }
    }
    
    /**
     * Check if player has a key in inventory
     */
    private function playerHasKey($player): bool
    {
        $inventory = $player->getInventory();
        
        // Debug log the entire inventory structure
        error_log('DEBUG AI: Checking for key in inventory: ' . json_encode($inventory));
        
        // The inventory is structured by category: ['key' => [...], 'weapon' => [...], 'spell' => [...], 'treasure' => [...]]
        // Check the 'key' category specifically
        $keyFound = false;
        
        // Check if inventory has a 'key' category with items
        if (isset($inventory['key']) && is_array($inventory['key']) && count($inventory['key']) > 0) {
            $keyFound = true;
            error_log('DEBUG AI: Found KEY(s) in inventory[key]: ' . json_encode($inventory['key']));
        }
        
        // Also check flat structure in case it's returned differently in some contexts
        if (!$keyFound) {
            foreach ($inventory as $category => $items) {
                if ($category === 'key' && is_array($items) && count($items) > 0) {
                    $keyFound = true;
                    error_log('DEBUG AI: Found KEY(s) in category: ' . $category . ' => ' . json_encode($items));
                    break;
                }
                
                // Check items within categories for keys
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if (is_array($item) && isset($item['type']) && $item['type'] === 'key') {
                            $keyFound = true;
                            error_log('DEBUG AI: Found KEY in ' . $category . ' category: ' . json_encode($item));
                            break 2;
                        }
                    }
                }
            }
        }
        
        // Log inventory summary
        $weaponCount = isset($inventory['weapon']) ? count($inventory['weapon']) : 0;
        $spellCount = isset($inventory['spell']) ? count($inventory['spell']) : 0;
        $treasureCount = isset($inventory['treasure']) ? count($inventory['treasure']) : 0;
        $keyCount = isset($inventory['key']) ? count($inventory['key']) : 0;
        
        error_log("DEBUG AI: Inventory summary - Weapons: {$weaponCount}, Spells: {$spellCount}, Treasures: {$treasureCount}, Keys: {$keyCount}");
        
        return $keyFound;
    }
    
    /**
     * Recursively search for key in any data structure
     */
    private function searchForKeyRecursive($data): bool
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // Check if the key name indicates a key
                if (is_string($key) && (stripos($key, 'key') !== false)) {
                    if ($value !== null && $value !== false && $value !== [] && $value !== '') {
                        return true;
                    }
                }
                
                // Check the value
                if (is_string($value) && (stripos($value, 'key') !== false || stripos($value, 'turnkey') !== false)) {
                    return true;
                }
                
                // Recurse into arrays and objects
                if (is_array($value) || is_object($value)) {
                    if ($this->searchForKeyRecursive($value)) {
                        return true;
                    }
                }
            }
        } elseif (is_object($data)) {
            return $this->searchForKeyRecursive((array)$data);
        }
        
        return false;
    }
    
    /**
     * Check if an item is a chest
     */
    private function isChestItem($item): bool
    {
        if ($item instanceof \App\Game\Item\Item) {
            $itemType = $item->type->value ?? '';
            $itemName = $item->name->value ?? '';
            
            // Chests typically have:
            // - type: 'chest' (NOT ruby_chest - that's the dragon!)
            // - name: 'treasure_chest' or 'fallen' (which drops a chest)
            // - guardHP: 0 (no guard, just locked)
            // - treasureValue: > 0
            
            // Dragon has type 'ruby_chest' but is a BOSS not a chest!
            // Check if it's a dragon first
            if ($itemName === 'dragon' || ($itemType === 'ruby_chest' && $item->guardHP > 0)) {
                return false; // Dragon is a boss to fight, not a chest to unlock
            }
            
            if ($itemType === 'chest') {
                return true;
            }
            
            if ($itemName === 'treasure_chest') {
                return true;
            }
            
            // Fallen drops a chest when defeated
            if ($itemName === 'fallen' && $item->guardDefeated) {
                return true;
            }
            
            // Check if it's a locked treasure (chest characteristics)
            if ($item->guardHP === 0 && $item->treasureValue > 0 && $item->isLocked()) {
                return true;
            }
        } elseif (is_array($item)) {
            $type = $item['type'] ?? '';
            $name = $item['name'] ?? '';
            $guardHP = $item['guardHP'] ?? -1;
            $treasureValue = $item['treasureValue'] ?? 0;
            $locked = $item['locked'] ?? false;
            
            // Dragon has type 'ruby_chest' but is a BOSS not a chest!
            if ($name === 'dragon' || ($type === 'ruby_chest' && $guardHP > 0)) {
                return false; // Dragon is a boss to fight, not a chest to unlock
            }
            
            if ($type === 'chest') {
                return true;
            }
            
            if ($name === 'treasure_chest') {
                return true;
            }
            
            if ($guardHP === 0 && $treasureValue > 0 && $locked) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if a position has a chest
     */
    private function positionHasChest(string $position, $field): bool
    {
        // Skip if we've already collected or tried this chest
        if (isset($this->collectedChests[$position])) {
            error_log('DEBUG AI: Skipping position ' . $position . ' - already tried this chest');
            return false;
        }
        
        // Log what we're checking
        error_log('DEBUG AI: Checking position ' . $position . ' for chest');
        
        // Method 1: Check items on the field first (most reliable for chests)
        $items = $field->getItems();
        if (isset($items[$position])) {
            $item = $items[$position];
            
            // Handle both Item objects and arrays
            if ($item instanceof \App\Game\Item\Item) {
                error_log('DEBUG AI: Found Item object at position ' . $position . ': ' . json_encode($item->toArray()));
                
                // Check if it's a chest (has treasure value, is locked, and no guard or guard defeated)
                $isChest = false;
                
                // Check if locked (chests are typically locked)
                if ($item->isLocked()) {
                    $isChest = true;
                }
                
                // Check if has treasure value
                if ($item->treasureValue > 0) {
                    $isChest = true;
                }
                
                // Check type/name
                $itemType = $item->type->value ?? '';
                $itemName = $item->name->value ?? '';
                if (stripos($itemType, 'chest') !== false || stripos($itemName, 'chest') !== false) {
                    $isChest = true;
                }
                
                // Make sure it's not a monster (chests have guardHP = 0)
                if ($isChest && $item->guardHP === 0) {
                    error_log('DEBUG AI: Found chest at position ' . $position . ' (locked=' . ($item->isLocked() ? 'true' : 'false') . ', treasure=' . $item->treasureValue . ')');
                    return true;
                }
            } elseif (is_array($item)) {
                error_log('DEBUG AI: Found item array at position ' . $position . ': ' . json_encode($item));
                
                // Handle array format (legacy)
                $isChest = false;
                
                // Check if locked (chests are typically locked)
                if (isset($item['locked']) && $item['locked'] === true) {
                    $isChest = true;
                }
                
                // Check if has treasure value
                if (isset($item['treasureValue']) && $item['treasureValue'] > 0) {
                    $isChest = true;
                }
                
                // Check type/name
                $itemType = $item['type'] ?? $item['name'] ?? '';
                if (stripos($itemType, 'chest') !== false) {
                    $isChest = true;
                }
                
                // Make sure it's not a monster guarding something
                if ($isChest && (!isset($item['guardHP']) || $item['guardHP'] === 0 || $item['guardHP'] === null)) {
                    error_log('DEBUG AI: Found chest at position ' . $position . ' (locked=' . ($item['locked'] ?? 'false') . ', treasure=' . ($item['treasureValue'] ?? 0) . ')');
                    return true;
                }
            }
        }
        
        // Method 2: Check tile features if tile exists
        $placedTiles = $field->getPlacedTiles(); // This returns array<position, tileId>
        if (isset($placedTiles[$position])) {
            $tileId = $placedTiles[$position];
            
            // Convert string to Uuid if needed
            if (is_string($tileId)) {
                try {
                    $tileId = Uuid::fromString($tileId);
                } catch (\Throwable $e) {
                    error_log('DEBUG AI: Could not parse tile ID: ' . $tileId);
                }
            }
            
            // Get the actual tile object from cache
            if ($tileId instanceof Uuid) {
                $tile = $field->getTileFromCache($tileId);
                if ($tile) {
                    error_log('DEBUG AI: Tile at ' . $position . ' has features: ' . json_encode($tile->getFeatures()));
                    
                    // Check tile features for chest
                    $features = $tile->getFeatures();
                    foreach ($features as $feature) {
                        // Handle different feature types
                        $featureString = '';
                        if ($feature instanceof \App\Game\Field\TileFeature) {
                            $featureString = $feature->value;  // Get the enum value
                        } elseif (is_string($feature)) {
                            $featureString = $feature;
                        } elseif (is_array($feature) && isset($feature['type'])) {
                            $featureString = $feature['type'];
                        }
                        
                        // Check if feature is a chest (various possible representations)
                        if ($featureString === 'chest' || 
                            $featureString === 'treasure' ||
                            stripos($featureString, 'chest') !== false ||
                            (is_array($feature) && isset($feature['treasure']) && $feature['treasure'] === true)) {
                            error_log('DEBUG AI: Found chest in tile features at ' . $position);
                            return true;
                        }
                    }
                }
            }
        }
        
        // Method 3: Check tile features using the feature tracking method
        try {
            // Get features from field (this includes chests)
            $features = $field->getTileFeatures($this->messageBus);
            if (isset($features[$position])) {
                $tileFeatures = $features[$position];
                
                // Convert features to strings for comparison
                $featureStrings = [];
                foreach ($tileFeatures as $feature) {
                    if ($feature instanceof \App\Game\Field\TileFeature) {
                        $featureStrings[] = $feature->value;
                    } elseif (is_string($feature)) {
                        $featureStrings[] = $feature;
                    }
                }
                
                error_log('DEBUG AI: Tile features from getTileFeatures at ' . $position . ': ' . json_encode($featureStrings));
                
                // Check for chest in features
                if (in_array('chest', $featureStrings) || in_array('treasure', $featureStrings)) {
                    error_log('DEBUG AI: Found chest/treasure in tile features at ' . $position);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            error_log('DEBUG AI: Could not check tile features: ' . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Determine which side of the new tile needs to be open based on relative positions
     */
    private function determineRequiredOpenSide(int $fromX, int $fromY, int $toX, int $toY): TileSide
    {
        $deltaX = $toX - $fromX;
        $deltaY = $toY - $fromY;
        
        // The new tile needs to have an open side facing the current tile
        // If we're placing a tile to the right (+X), it needs LEFT side open to connect
        // If we're placing a tile to the left (-X), it needs RIGHT side open to connect
        // If we're placing a tile below (+Y), it needs TOP side open to connect
        // If we're placing a tile above (-Y), it needs BOTTOM side open to connect
        
        if ($deltaX > 0) {
            return TileSide::LEFT;   // New tile is to the right, needs left side open
        } elseif ($deltaX < 0) {
            return TileSide::RIGHT;  // New tile is to the left, needs right side open
        } elseif ($deltaY > 0) {
            return TileSide::TOP;    // New tile is below, needs top side open
        } elseif ($deltaY < 0) {
            return TileSide::BOTTOM; // New tile is above, needs bottom side open
        }
        
        // Default fallback (shouldn't happen in normal gameplay)
        return TileSide::TOP;
    }
    
    /**
     * Check if item info represents a chest or treasure
     */
    private function isChestOrTreasure(array $itemInfo): bool
    {
        // Check if it's explicitly a chest
        if (isset($itemInfo['type']) && $itemInfo['type'] === 'chest') {
            return true;
        }
        
        // Check if it's marked as treasure
        if (isset($itemInfo['treasure']) && $itemInfo['treasure'] === true) {
            return true;
        }
        
        // Check if reward type is treasure
        if (isset($itemInfo['reward']['type']) && $itemInfo['reward']['type'] === 'treasure') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Handle chest pickup - ALWAYS attempt to pick up treasures!
     */
    private function handleChestPickup(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array $moveResponse, array &$actions): void
    {
        $actions[] = $this->createAction('treasure_found', ['message' => 'Found treasure chest! Attempting pickup']);
        
        // Get current position
        $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        [$x, $y] = explode(',', $currentPosition->toString());
        
        // Attempt to pick up the treasure
        $pickupResult = $this->apiClient->pickItem($gameId, $playerId, $currentTurnId, (int)$x, (int)$y);
        $actions[] = $this->createAction('treasure_pickup', ['result' => $pickupResult]);
        
        if ($pickupResult['success']) {
            $actions[] = $this->createAction('treasure_collected', ['message' => 'Successfully collected treasure!']);
            
            // Picking up a chest ENDS THE TURN according to game rules
            $actions[] = $this->createAction('ai_info', ['message' => 'Chest pickup ends turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
        } else {
            // Failed to pick up treasure - check why
            $response = $pickupResult['response'] ?? [];
            $missingKey = $response['missingKey'] ?? false;
            
            if ($missingKey) {
                $actions[] = $this->createAction('treasure_failed', [
                    'message' => 'Failed to collect treasure - missing key!',
                    'reason' => 'missingKey'
                ]);
                // Mark this chest to avoid trying again
                $this->collectedChests[$currentPosition->toString()] = true;
            } else {
                $actions[] = $this->createAction('treasure_failed', ['message' => 'Failed to collect treasure']);
            }
            
            // If we failed to pick up chest, continue playing
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
        }
    }
    
    /**
     * Get reasoning for why we're moving
     */
    private function getMovementReason($player, $field, array $moveOptions, bool $hasKey): string
    {
        // Check for treasure
        if ($hasKey) {
            foreach ($moveOptions as $position) {
                if ($this->positionHasChest($position, $field)) {
                    return 'Moving to chest location - have key to unlock treasure!';
                }
            }
        }
        
        // Check for weapon upgrades
        if ($this->shouldMoveToUpgradeWeapon($player, $field, $moveOptions)) {
            // Find which weapon we're going for
            $items = $field->getItems();
            foreach ($moveOptions as $position) {
                if (isset($items[$position])) {
                    $reward = $this->getMonsterReward($items[$position]);
                    if ($reward && in_array($reward, ['dagger', 'sword', 'axe'])) {
                        $monsterHP = $this->getMonsterHP($items[$position]);
                        $playerStrength = $this->calculateEffectiveStrength($player);
                        if ($monsterHP > 0 && $playerStrength >= $monsterHP) {
                            return "Moving to defeat monster for {$reward} upgrade (HP: {$monsterHP} vs our strength: {$playerStrength})";
                        }
                    }
                }
            }
        }
        
        // Check HP for healing need
        $hp = $player->getHP();
        $healingThreshold = $this->strategyConfig['healingThreshold'] ?? 2;
        if ($hp <= $healingThreshold) {
            return "Low HP ($hp), moving to find healing (threshold: $healingThreshold)";
        }
        
        // Check for winnable battles
        $playerStrength = $this->calculateEffectiveStrength($player);
        return "Looking for battles or items (strength: $playerStrength)";
    }
    
    /**
     * Get specific reason for moving to a position
     */
    private function getSpecificMoveReason(string $targetPosition, $field, $player): string
    {
        // Check what's at the target position
        if ($this->positionHasChest($targetPosition, $field)) {
            // Only say we're moving to collect treasure if we have a key
            if ($this->playerHasKey($player)) {
                return 'Target has a chest - moving to collect treasure';
            } else {
                return 'Exploring area (chest present but no key)';
            }
        }
        
        $items = $field->getItems();
        if (isset($items[$targetPosition])) {
            $item = $items[$targetPosition];
            
            // Handle both Item objects and arrays
            if ($item instanceof \App\Game\Item\Item) {
                if ($item->guardHP > 0 && !$item->guardDefeated) {
                    $monsterHP = $item->guardHP;
                    $monsterName = $item->name->value ?? 'monster';
                    $playerStrength = $this->calculateEffectiveStrength($player);
                    if ($playerStrength >= $monsterHP) {
                        return "Moving to fight $monsterName (HP: $monsterHP vs our strength: $playerStrength)";
                    } else {
                        return "Moving despite risky battle with $monsterName (HP: $monsterHP vs our strength: $playerStrength)";
                    }
                }
            } elseif (is_array($item)) {
                if (isset($item['monster'])) {
                    $monsterHP = $item['monster']['hp'] ?? 0;
                    $playerStrength = $this->calculateEffectiveStrength($player);
                    if ($playerStrength >= $monsterHP) {
                        return "Moving to fight monster (HP: $monsterHP vs our strength: $playerStrength)";
                    } else {
                        return "Moving despite risky battle (monster HP: $monsterHP vs our strength: $playerStrength)";
                    }
                }
            }
        }
        
        // Check for healing fountain
        try {
            $features = $field->getTileFeatures($this->messageBus);
            if (isset($features[$targetPosition])) {
                $tileFeatures = $features[$targetPosition];
                
                // Check for healing fountain (handle both string and TileFeature enum)
                foreach ($tileFeatures as $feature) {
                    $featureString = '';
                    if ($feature instanceof \App\Game\Field\TileFeature) {
                        $featureString = $feature->value;
                    } elseif (is_string($feature)) {
                        $featureString = $feature;
                    }
                    
                    if ($featureString === 'healing_fountain' || $featureString === TileFeature::HEALING_FOUNTAIN->value) {
                        return 'Moving to healing fountain';
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback: try to get tile from cache
            $tiles = $field->getPlacedTiles();
            if (isset($tiles[$targetPosition])) {
                $tileId = $tiles[$targetPosition];
                if (is_string($tileId)) {
                    $tileId = Uuid::fromString($tileId);
                }
                if ($tileId instanceof Uuid) {
                    $tile = $field->getTileFromCache($tileId);
                    if ($tile && in_array('healing_fountain', $tile->getFeatures())) {
                        return 'Moving to healing fountain';
                    }
                }
            }
        }
        
        return 'Exploring new area';
    }
    
    /**
     * Get reason for tile placement choice
     */
    private function getTilePlacementReason(string $chosenPlace, array $placeTileOptions, $field, $player): string
    {
        $count = count($placeTileOptions);
        if ($count === 1) {
            return 'Only one placement option available';
        }
        
        // Explain strategy-based choice
        if ($this->strategyConfig['preferBattles'] ?? false) {
            return "Aggressive placement - expanding quickly ($count options)";
        } else {
            return "Defensive placement - staying compact ($count options)";
        }
    }
    
    /**
     * Pick up chest at current position
     */
    private function pickupChestAtCurrentPosition(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, $currentPosition, array &$actions): void
    {
        $actions[] = $this->createAction('ai_reasoning', ['message' => 'Found chest at current position and I have a key - must collect treasure for victory!']);
        $actions[] = $this->createAction('chest_pickup_attempt', ['message' => 'Attempting to pick up chest at current position']);
        
        [$x, $y] = explode(',', $currentPosition->toString());
        
        // Attempt to pick up the chest/treasure
        $pickupResult = $this->apiClient->pickItem($gameId, $playerId, $currentTurnId, (int)$x, (int)$y);
        $actions[] = $this->createAction('chest_pickup_result', ['result' => $pickupResult]);
        
        if ($pickupResult['success']) {
            $actions[] = $this->createAction('treasure_collected', ['message' => 'Successfully collected treasure from chest!']);
            // Continue playing after collecting treasure
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
        } else {
            // Check if we need a key or other issue
            $response = $pickupResult['response'] ?? [];
            if (isset($response['missingKey']) && $response['missingKey']) {
                $actions[] = $this->createAction('chest_locked', ['message' => 'Chest is locked but we thought we had a key!']);
            } else {
                $actions[] = $this->createAction('chest_pickup_failed', ['message' => 'Failed to pick up chest', 'reason' => $response['message'] ?? 'Unknown']);
            }
            // Continue playing even if pickup failed
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
        }
    }
    
    /**
     * Find the dragon boss on the field
     */
    private function findDragonBoss($field): ?array
    {
        $items = $field->getItems();
        
        foreach ($items as $position => $item) {
            // Convert to Item object if needed
            if (!($item instanceof \App\Game\Item\Item)) {
                $item = \App\Game\Item\Item::fromAnything($item);
            }
            
            $itemName = $item->name->value ?? 'unknown';
            $itemType = $item->type->value ?? 'unknown';
            $guardHP = $item->guardHP ?? 0;
            $isLocked = $item->isLocked();
            
            // Dragon has type 'ruby_chest' and name 'dragon' 
            // It's locked and has HP > 0
            if (($itemName === 'dragon' || $itemType === 'ruby_chest') && 
                $isLocked && $guardHP > 0 && !$item->guardDefeated) {
                return [
                    'position' => $position,
                    'hp' => $guardHP,
                    'locked' => true,
                    'defeated' => false
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Calculate chest score for a player
     */
    private function calculateChestScore($player): int
    {
        $inventory = $player->getInventory();
        $score = 0;
        
        // Count chests in inventory
        $chests = $inventory['chest'] ?? [];
        foreach ($chests as $chest) {
            // Ruby chest = 3 points, other chests = 1 point
            $chestType = $chest instanceof \App\Game\Item\Item ? $chest->type->value : ($chest['type'] ?? '');
            $score += ($chestType === 'ruby_chest') ? 3 : 1;
        }
        
        return $score;
    }
    
    /**
     * Get opponent chest scores
     */
    private function getOpponentChestScores(Uuid $gameId, Uuid $playerId): array
    {
        $scores = [];
        
        try {
            $players = $this->messageBus->dispatch(new GetActivePlayers($gameId));
            foreach ($players as $player) {
                if ($player->getId()->toString() !== $playerId->toString()) {
                    $scores[] = $this->calculateChestScore($player);
                }
            }
        } catch (\Throwable $e) {
            error_log("DEBUG AI: Error getting opponent scores: " . $e->getMessage());
        }
        
        return $scores;
    }
    
    /**
     * Get adjacent positions to a given position
     */
    private function getAdjacentPositions(string $position): array
    {
        [$x, $y] = explode(',', $position);
        $x = (int)$x;
        $y = (int)$y;
        
        return [
            ($x - 1) . ',' . $y,  // Left
            ($x + 1) . ',' . $y,  // Right
            $x . ',' . ($y - 1),  // Up
            $x . ',' . ($y + 1),  // Down
        ];
    }
    
    /**
     * Find best move toward a target position
     */
    /**
     * Find complete path to target using BFS respecting tile transitions
     */
    private function findPathToTarget(string $start, string $target, array $placedTiles, ?Field $field = null, array &$actions = []): array
    {
        // If field not provided, we can't use transitions - fallback to adjacency check
        if ($field === null) {
            error_log("DEBUG AI: WARNING - findPathToTarget called without Field, using fallback adjacency check");
            return $this->findPathToTargetFallback($start, $target, $placedTiles);
        }
        
        $queue = [[$start]];
        $visited = [$start => true];
        $iterations = 0;
        $maxIterations = 1000; // Prevent infinite loops
        $transitionLog = [];
        
        while (!empty($queue) && $iterations < $maxIterations) {
            $iterations++;
            $path = array_shift($queue);
            $current = end($path);
            
            // Check if we reached the target
            if ($current === $target) {
                if (!empty($actions)) {
                    $actions[] = $this->createAction('pathfinding_debug', [
                        'found' => true,
                        'iterations' => $iterations,
                        'path' => implode(' -> ', $path)
                    ]);
                }
                // Don't remove starting position - return full path
                return $path;
            }
            
            // Get valid transitions from current position
            $currentPlace = FieldPlace::fromString($current);
            $transitions = $field->getTransitionsFrom($currentPlace);
            
            if ($iterations <= 5) { // Log first few iterations
                $transitionLog[$current] = $transitions;
            }
            
            foreach ($transitions as $neighbor) {
                // Check if neighbor hasn't been visited
                if (!isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $newPath = $path;
                    $newPath[] = $neighbor;
                    $queue[] = $newPath;
                }
            }
        }
        
        if (!empty($actions)) {
            $actions[] = $this->createAction('pathfinding_debug', [
                'found' => false,
                'iterations' => $iterations,
                'visited_count' => count($visited),
                'visited_sample' => array_slice(array_keys($visited), 0, 10),
                'transitions_sample' => $transitionLog,
                'reason' => "Could not reach {$target} from {$start}"
            ]);
        }
        return []; // No path found
    }
    
    /**
     * Fallback pathfinding using simple adjacency (when Field not available)
     */
    private function findPathToTargetFallback(string $start, string $target, array $placedTiles): array
    {
        $queue = [[$start]];
        $visited = [$start => true];
        
        while (!empty($queue)) {
            $path = array_shift($queue);
            $current = end($path);
            
            if ($current === $target) {
                array_shift($path);
                return $path;
            }
            
            [$x, $y] = explode(',', $current);
            $neighbors = [
                ($x-1) . ',' . $y,
                ($x+1) . ',' . $y,
                $x . ',' . ($y-1),
                $x . ',' . ($y+1)
            ];
            
            foreach ($neighbors as $neighbor) {
                if (isset($placedTiles[$neighbor]) && !isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $newPath = $path;
                    $newPath[] = $neighbor;
                    $queue[] = $newPath;
                }
            }
        }
        
        return [];
    }
    
    private function findBestMoveToward(string $currentPos, string $targetPos, array $moveOptions, array $placedTiles): ?string
    {
        [$currentX, $currentY] = explode(',', $currentPos);
        $currentX = (int)$currentX;
        $currentY = (int)$currentY;
        
        [$targetX, $targetY] = explode(',', $targetPos);
        $targetX = (int)$targetX;
        $targetY = (int)$targetY;
        
        $bestMove = null;
        $shortestDistance = PHP_INT_MAX;
        
        foreach ($moveOptions as $moveOption) {
            // Ensure the move option has a tile
            if (!isset($placedTiles[$moveOption])) {
                continue;
            }
            
            [$moveX, $moveY] = explode(',', $moveOption);
            $moveX = (int)$moveX;
            $moveY = (int)$moveY;
            
            // Calculate Manhattan distance to target
            $distance = abs($moveX - $targetX) + abs($moveY - $targetY);
            
            if ($distance < $shortestDistance) {
                $shortestDistance = $distance;
                $bestMove = $moveOption;
            }
        }
        
        return $bestMove;
    }
    
    /**
     * Find a position adjacent to a winnable locked monster (like the dragon)
     */
    private function findPositionAdjacentToWinnableMonster(array $moveOptions, $field, $player, $gameId, $playerId): ?string
    {
        $items = $field->getItems();
        $playerStrength = $this->calculateEffectiveStrength($player);
        $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
        [$currentX, $currentY] = explode(',', $currentPosition->toString());
        
        // Check all items on the field for locked monsters we can defeat
        foreach ($items as $monsterPos => $item) {
            if (!($item instanceof \App\Game\Item\Item)) {
                continue;
            }
            
            // Check if this is a locked monster we can defeat
            if ($item->isLocked() && $item->guardHP > 0 && !$item->guardDefeated) {
                // Special check for dragon
                $isMonster = ($item->name->value === 'dragon') || 
                            ($item->type->value === 'ruby_chest' && $item->guardHP > 0) ||
                            ($item->guardHP > 0 && !in_array($item->type->value, ['chest', 'treasure_chest']));
                
                if ($isMonster && $playerStrength >= $item->guardHP) {
                    error_log("DEBUG AI: Found locked monster {$item->name->value} at {$monsterPos} with HP {$item->guardHP} (we can defeat it!)");
                    
                    // Find adjacent positions we can move to
                    [$monsterX, $monsterY] = explode(',', $monsterPos);
                    $adjacentPositions = [
                        ($monsterX + 1) . ',' . $monsterY,
                        ($monsterX - 1) . ',' . $monsterY,
                        $monsterX . ',' . ($monsterY + 1),
                        $monsterX . ',' . ($monsterY - 1),
                    ];
                    
                    // Return the first adjacent position we can move to
                    foreach ($adjacentPositions as $adjPos) {
                        if (in_array($adjPos, $moveOptions)) {
                            error_log("DEBUG AI: Can move to {$adjPos} which is adjacent to monster at {$monsterPos}");
                            return $adjPos;
                        }
                    }
                    
                    // If we're already adjacent, that's even better - but we should have attacked already
                    if (in_array($currentPosition->toString(), $adjacentPositions)) {
                        error_log("DEBUG AI: Already adjacent to monster at {$monsterPos}, should attack!");
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find positions that are on the edge of explored area (likely to have tile placement options)
     */
    private function findEdgePositions(array $positions, $field): array
    {
        $edgePositions = [];
        $placedTiles = $field->getPlacedTiles();
        
        foreach ($positions as $position) {
            [$x, $y] = explode(',', $position);
            $x = (int)$x;
            $y = (int)$y;
            
            // Check if this position has empty adjacent spaces where tiles could be placed
            $adjacentPositions = [
                ($x + 1) . ',' . $y,  // East
                ($x - 1) . ',' . $y,  // West
                $x . ',' . ($y + 1),  // North
                $x . ',' . ($y - 1),  // South
            ];
            
            $hasEmptyAdjacent = false;
            foreach ($adjacentPositions as $adjPos) {
                if (!isset($placedTiles[$adjPos])) {
                    // This adjacent position has no tile, so current position is on the edge
                    $hasEmptyAdjacent = true;
                    break;
                }
            }
            
            if ($hasEmptyAdjacent) {
                $edgePositions[] = $position;
            }
        }
        
        return $edgePositions;
    }
    
    /**
     * Check if player is currently on a healing fountain
     */
    /**
     * Calculate Manhattan distance between two positions
     */
    private function calculateManhattanDistance(string $pos1, string $pos2): int
    {
        [$x1, $y1] = explode(',', $pos1);
        [$x2, $y2] = explode(',', $pos2);
        return abs((int)$x1 - (int)$x2) + abs((int)$y1 - (int)$y2);
    }
    
    private function isOnHealingFountain(string $position, $field): bool
    {
        $placedTiles = $field->getPlacedTiles();
        
        // Starting position (0,0) always has a healing fountain
        if ($position === '0,0') {
            return true;
        }
        
        if (isset($placedTiles[$position])) {
            $tileId = $placedTiles[$position];
            if (is_string($tileId)) {
                $tileId = Uuid::fromString($tileId);
            }
            if ($tileId instanceof Uuid) {
                $tile = $field->getTileFromCache($tileId);
                if ($tile) {
                    $features = $tile->getFeatures();
                    foreach ($features as $feature) {
                        $featureString = '';
                        if ($feature instanceof \App\Game\Field\TileFeature) {
                            $featureString = $feature->value;
                        } elseif (is_string($feature)) {
                            $featureString = $feature;
                        }
                        if ($featureString === 'healing_fountain' || $featureString === TileFeature::HEALING_FOUNTAIN->value) {
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Find a healing fountain in the available move options
     */
    private function findHealingFountainInMoveOptions(array $moveOptions, $field): ?string
    {
        foreach ($moveOptions as $position) {
            if ($this->isOnHealingFountain($position, $field)) {
                return $position;
            }
        }
        
        return null;
    }
}