<?php

declare(strict_types=1);

namespace App\Game\AI;

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
    private static array $turnsSinceLastProgress = [];  // Track turns without progress per game/player
    private static array $lastFieldStateHash = [];  // Track field state to detect when new tiles are placed
    private static array $unPickableItems = [];  // Track items that couldn't be picked up due to full inventory
    private static array $persistentTargets = [];  // Track current target position across turns per game/player
    private static array $persistentTargetReasons = [];  // Track why we're pursuing targets across turns
    private static array $explorationTargets = [];  // Track exploration targets to prevent loops
    private static array $explorationHistory = [];  // Track positions explored across turns
    private int $moveCount = 0;  // Track number of moves in current turn
    
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
        
        // Clear visited positions and reset move counter at the start of each turn
        $this->visitedPositions = [];
        $this->moveCount = 0;
        
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
            
            // Debug: Log available options
            $hasKey = $this->playerHasKey($player);
            
            // Debug: Log all items on the field
            $items = $field->getItems();
            error_log('DEBUG AI: All items on field: ' . json_encode(array_keys($items)));
            error_log('DEBUG AI: MoveToOptions: ' . json_encode($moveToOptions));
            
            // Log details about each item
            foreach ($items as $pos => $item) {
                if ($item instanceof \App\Game\Item\Item) {
                    $itemType = $item->type->value ?? 'unknown';
                    $itemName = $item->name->value ?? 'unknown';
                    $guardHP = $item->guardHP;
                    $guardDefeated = $item->guardDefeated ? 'true' : 'false';
                    $isLocked = $item->isLocked() ? 'true' : 'false';
                    $treasureValue = $item->treasureValue;
                    
                    error_log("DEBUG AI: Item at {$pos}:");
                    error_log("  - type: {$itemType}");
                    error_log("  - name: {$itemName}");
                    error_log("  - guardHP: {$guardHP}");
                    error_log("  - guardDefeated: {$guardDefeated}");
                    error_log("  - isLocked: {$isLocked}");
                    error_log("  - treasureValue: {$treasureValue}");
                    
                    // Check if this is a chest
                    if ($itemType === 'chest' || $itemName === 'treasure_chest' || 
                        ($guardHP === 0 && $treasureValue > 0)) {
                        error_log("  -> This appears to be a CHEST!");
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
            
            $actions[] = $this->createAction('ai_options', [
                'moveOptions' => count($moveToOptions),
                'tileOptions' => count($placeTileOptions),
                'hasKey' => $hasKey,
                'currentPosition' => $currentPosition->toString(),
                'visibleChests' => $visibleChests,
                'chestCount' => count($visibleChests),
                'chestsOnField' => $allChestsOnField,  // All chests on the field
                'betterWeaponsImmediate' => $betterWeaponsImmediate,
                'betterWeaponsOnField' => $betterWeaponsOnField
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
            if ($player->getHP() <= 1 && !empty($moveToOptions)) {
                $healingFountainPosition = $this->findHealingFountainInMoveOptions($moveToOptions, $field);
                if ($healingFountainPosition !== null) {
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
                    
                    $this->handleMoveResult($gameId, $playerId, $currentTurnId, $moveToOptions, $actions);
                    return $actions;
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
            
            // Priority 1: Move towards chests if we have a key
            if ($hasKey && !empty($allChestsOnField) && empty($visibleChests)) {
                $shouldMoveTowardsChest = true;
                error_log("DEBUG AI: Have key and chests on field - should move towards them!");
            }
            
            // Priority 2: Move towards better weapons
            if (!$shouldMoveTowardsChest && !empty($betterWeaponsOnField) && empty($betterWeaponsImmediate)) {
                // We have better weapons on field but can't reach them immediately
                // We should move towards them if we can
                $shouldMoveTowardsBetterWeapon = true;
                error_log("DEBUG AI: Should move towards better weapons on field");
            }
            
            if ($this->shouldMoveBeforePlacingTile($player, $field, $moveToOptions) || 
                (!empty($betterWeaponsImmediate)) ||
                ($shouldMoveTowardsChest && !empty($moveToOptions)) ||
                ($shouldMoveTowardsBetterWeapon && !empty($moveToOptions))) {
                
                $reason = $this->getMovementReason($player, $field, $moveToOptions, $hasKey);
                
                // Update reason based on what we're moving towards
                if ($shouldMoveTowardsChest) {
                    $reason = "Moving towards chests on field (have key!): " . implode(', ', $allChestsOnField);
                } elseif ($shouldMoveTowardsBetterWeapon && empty($betterWeaponsImmediate)) {
                    $reason = "Moving towards better weapons on field: " . implode(', ', $betterWeaponsOnField);
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
                } else {
                    $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
                }
            } elseif (!empty($placeTileOptions)) {
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
        
        // Find the valid move option that gets us closer to a target
        $bestMove = null;
        $shortestDistance = PHP_INT_MAX;
        $closestTarget = null;
        
        [$currentX, $currentY] = explode(',', $currentPosition->toString());
        $currentX = (int)$currentX;
        $currentY = (int)$currentY;
        
        foreach ($validMoveOptions as $moveOption) {
            // Skip positions we've already visited in this turn to prevent oscillation
            if (isset($this->visitedPositions[$moveOption])) {
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
            
            // Calculate distance from this move option to all targets
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
                
                // Manhattan distance from move option to target
                $distance = abs($moveX - $targetX) + abs($moveY - $targetY);
                
                if ($distance < $shortestDistance) {
                    $shortestDistance = $distance;
                    $bestMove = $moveOption;
                    $closestTarget = $targetPosition;
                }
            }
        }
        
        if ($bestMove) {
            error_log("DEBUG AI: Best move is {$bestMove} towards {$targetType} at {$closestTarget} (distance: {$shortestDistance})");
            
            // Track progress towards this target
            if (!isset(self::$turnsSinceLastProgress[$trackingKey][$closestTarget])) {
                self::$turnsSinceLastProgress[$trackingKey][$closestTarget] = ['distance' => $shortestDistance, 'turns' => 0];
            } else {
                $previousDistance = self::$turnsSinceLastProgress[$trackingKey][$closestTarget]['distance'];
                if ($shortestDistance >= $previousDistance) {
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
                    self::$turnsSinceLastProgress[$trackingKey][$closestTarget] = ['distance' => $shortestDistance, 'turns' => 0];
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
        
        // Find the valid move option that gets us closer to a better weapon
        $bestMove = null;
        $shortestDistance = PHP_INT_MAX;
        $targetWeapon = null;
        
        [$currentX, $currentY] = explode(',', $currentPosition->toString());
        $currentX = (int)$currentX;
        $currentY = (int)$currentY;
        
        foreach ($validMoveOptions as $moveOption) {
            // Skip positions we've already visited to prevent oscillation
            if (isset($this->visitedPositions[$moveOption])) {
                error_log("DEBUG AI: Skipping {$moveOption} - already visited this turn");
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
            
            // Calculate distance from this move option to all better weapons
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
                }
            }
        }
        
        if ($bestMove) {
            // Extract just the position from targetWeapon for tracking (e.g., "2,1" from "sword at 2,1")
            $targetPos = null;
            foreach ($reachableWeapons as $pos => $type) {
                if ($targetWeapon === "{$type} at {$pos}") {
                    $targetPos = $pos;
                    break;
                }
            }
            
            error_log("DEBUG AI: Best move is {$bestMove} towards {$targetWeapon} (distance: {$shortestDistance})");
            
            // Set persistent target so we continue pursuing it across multiple turns
            self::$persistentTargets[$trackingKey] = $targetPos;
            self::$persistentTargetReasons[$trackingKey] = $targetWeapon;
            error_log("DEBUG AI: Set persistent target to {$targetWeapon} at {$targetPos} for multi-turn pursuit");
            
            // Track progress towards this weapon position (not the full description)
            // Calculate actual distance from current position to target for progress tracking
            [$targetX, $targetY] = explode(',', $targetPos);
            $actualDistanceToTarget = abs($currentX - (int)$targetX) + abs($currentY - (int)$targetY);
            
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
                
                // If we haven't made progress for 2+ turns (reduced from 3 for faster detection), mark as temporarily unreachable
                if (self::$turnsSinceLastProgress[$trackingKey][$targetPos]['turns'] >= 2) {
                    self::$unreachableTargets[$trackingKey][$targetPos] = true;
                    error_log("DEBUG AI: Marking weapon at {$targetPos} as UNREACHABLE for now!");
                    
                    // Clear persistent target if it's now unreachable
                    if (self::$persistentTargets[$trackingKey] === $targetPos) {
                        error_log("DEBUG AI: Clearing persistent target at {$targetPos} as it's now unreachable");
                        self::$persistentTargets[$trackingKey] = null;
                        self::$persistentTargetReasons[$trackingKey] = null;
                    }
                    
                    $actions[] = $this->createAction('ai_reasoning', [
                        'message' => "Weapon at {$targetWeapon} appears unreachable for now, will try placing tiles to open new paths"
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
                    } else {
                        // No tiles to place, check if we hit move limit
                        if ($this->moveCount >= self::MAX_MOVES_PER_TURN) {
                            $actions[] = $this->createAction('ai_info', ['message' => 'Reached move limit for turn, ending turn']);
                        } else {
                            $actions[] = $this->createAction('ai_info', ['message' => 'Target unreachable and no tiles to place, ending turn']);
                        }
                        $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
                        $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
                    }
                    return;
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
                    // Inventory was full, mark this item as unpickable
                    $trackingKey = "{$gameId}_{$playerId}";
                    $currentPos = "{$x},{$y}";
                    
                    if (!isset(self::$unPickableItems[$trackingKey])) {
                        self::$unPickableItems[$trackingKey] = [];
                    }
                    self::$unPickableItems[$trackingKey][$currentPos] = true;
                    
                    $actions[] = $this->createAction('ai_info', ['message' => 'Inventory full, cannot pick up item']);
                    
                    // Continue turn after failed pickup
                    $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
                } else {
                    // Other failure, continue turn
                    $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
                }
            } else {
                $actions[] = $this->createAction('item_reasoning', [
                    'decision' => 'Skip item',
                    'reason' => $reason
                ]);
                // Not picking up, continue turn
                $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
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
            
            $actions[] = $this->createAction('continue_turn', ['message' => 'Looking for more actions']);
            
            // Get available actions from current position
            $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                gameId: $gameId,
                playerId: $playerId,
                messageBus: $this->messageBus,
            ));
            
            $moveToOptions = $availablePlaces['moveTo'] ?? [];
            $placeTileOptions = $availablePlaces['placeTile'] ?? [];
            
            error_log('DEBUG AI after move - moveToOptions: ' . json_encode($moveToOptions));
            error_log('DEBUG AI after move - placeTileOptions: ' . json_encode($placeTileOptions));
            error_log('DEBUG AI action count: ' . count($actions));
            
            // Check if we should continue moving towards better weapons
            $field = $this->messageBus->dispatch(new GetField($gameId));
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
            
            // Try to continue moving towards better weapons if they exist (but check move limit)
            if (!empty($betterWeaponsOnField) && !empty($unvisitedOptions) && $this->moveCount < self::MAX_MOVES_PER_TURN) {
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                $actions[] = $this->createAction('ai_reasoning', ['message' => 'Continuing to move towards better weapons']);
                $this->executeMoveTowardsBetterWeapon($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $betterWeaponsOnField, $actions);
            } elseif (!empty($placeTileOptions) && $this->moveCount < self::MAX_MOVES_PER_TURN) {
                // Place tiles to open new areas (but only if we haven't reached max moves)
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                $actions[] = $this->createAction('ai_reasoning', ['message' => 'Placing tile to expand exploration area']);
                $this->executeTilePlacement($gameId, $playerId, $currentTurnId, $currentPosition, $placeTileOptions, $actions, 0);
            } elseif (!empty($unvisitedOptions) && $this->moveCount < self::MAX_MOVES_PER_TURN) {
                // Continue exploring unvisited positions
                $currentPosition = $this->messageBus->dispatch(new GetPlayerPosition($gameId, $playerId));
                $actions[] = $this->createAction('ai_reasoning', ['message' => 'Continuing exploration of unvisited areas']);
                $this->executeMovement($gameId, $playerId, $currentTurnId, $currentPosition, $moveToOptions, $actions);
            } else {
                // No unvisited positions, no tiles to place, or reached move limit
                if (empty($unvisitedOptions)) {
                    $actions[] = $this->createAction('ai_info', ['message' => 'All reachable positions have been explored']);
                } else if ($this->moveCount >= self::MAX_MOVES_PER_TURN) {
                    $actions[] = $this->createAction('ai_info', ['message' => 'Reached maximum moves for this turn']);
                } else {
                    $actions[] = $this->createAction('ai_info', ['message' => 'No more actions available']);
                }
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
    private function continueAfterAction(Uuid $gameId, Uuid $playerId, Uuid $currentTurnId, array &$actions): void
    {
        // Check if battle occurred - if so, must end turn (can't move after battle)
        if ($this->hasBattleOccurred($actions)) {
            $actions[] = $this->createAction('ai_info', ['message' => 'Battle occurred, ending turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
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
        
        // Check action limit
        if (count($actions) > self::MAX_ACTIONS_PER_TURN) {
            $actions[] = $this->createAction('ai_info', ['message' => 'Action limit reached, ending turn']);
            $endResult = $this->apiClient->endTurn($gameId, $playerId, $currentTurnId);
            $actions[] = $this->createAction('end_turn', ['result' => $endResult]);
            return;
        }
        
        // Get available actions from current position
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
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
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
        $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
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
            $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
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
        $this->continueAfterAction($gameId, $playerId, $currentTurnId, $actions);
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
        
        $selectedConsumableIds = [];
        $pickupItem = false;
        
        // On draw, use consumables to win if available
        if ($battleResult === 'draw' && !empty($availableConsumables)) {
            // Use fireball if available (adds 9 damage)
            foreach ($availableConsumables as $consumable) {
                if ($consumable['type'] === 'fireball') {
                    $selectedConsumableIds[] = \App\Infrastructure\Uuid\Uuid::fromString($consumable['itemId']);
                    $pickupItem = true; // We'll win with fireball
                    $actions[] = $this->createAction('using_consumable', [
                        'type' => 'fireball',
                        'reason' => 'To win on draw'
                    ]);
                    break;
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
        $totalStrength = $baseHP;
        
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