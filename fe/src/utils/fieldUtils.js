/**
 * Utility functions for working with game field and player positions
 */

import { parseOrientationString } from './tileUtils';

// Constants
const FIELD_PADDING = 100;
const TILE_ANIMATION_DURATION = 1000;
const FIELD_ELEMENT_SELECTOR = '.game-field';

// Request throttling state
let isRequestInProgress = false;

/**
 * Reset the request lock
 */
export const resetRequestLock = () => {
  isRequestInProgress = false;
};

/**
 * Check if a request is currently in progress
 */
export const isRequestActive = () => {
  return isRequestInProgress;
};

// Utility functions
/**
 * Parse a position string into coordinates
 * @param {string} position - Position in format "x,y"
 * @returns {{x: number, y: number} | null} Parsed coordinates or null if invalid
 */
const parsePosition = (position) => {
  if (!position || typeof position !== 'string') {
    return null;
  }
  
  const [x, y] = position.split(',').map(Number);
  return (!isNaN(x) && !isNaN(y)) ? { x, y } : null;
};

/**
 * Get field element with caching
 * @returns {HTMLElement | null} Field element
 */
const getFieldElement = (() => {
  let cachedElement = null;
  return () => {
    if (!cachedElement || !document.contains(cachedElement)) {
      cachedElement = document.querySelector(FIELD_ELEMENT_SELECTOR);
    }
    return cachedElement;
  };
})();

/**
 * Calculate bounds from a list of coordinates
 * @param {Array<{x: number, y: number}>} coordinates - Array of coordinate objects
 * @returns {{minX: number, maxX: number, minY: number, maxY: number}} Bounds
 */
const calculateBounds = (coordinates) => {
  if (!coordinates.length) {
    return { minX: 0, maxX: 0, minY: 0, maxY: 0 };
  }
  
  return coordinates.reduce(
    (bounds, { x, y }) => ({
      minX: Math.min(bounds.minX, x),
      maxX: Math.max(bounds.maxX, x),
      minY: Math.min(bounds.minY, y),
      maxY: Math.max(bounds.maxY, y)
    }),
    { 
      minX: coordinates[0].x, 
      maxX: coordinates[0].x, 
      minY: coordinates[0].y, 
      maxY: coordinates[0].y 
    }
  );
};

/**
 * Calculate scroll position to center on coordinates
 * @param {number} centerX - Center X coordinate in pixels
 * @param {number} centerY - Center Y coordinate in pixels
 * @param {HTMLElement} fieldElement - Field DOM element
 * @param {Object} fieldSize - Field size information
 * @param {number} tileSize - Size of each tile
 * @returns {{left: number, top: number}} Scroll coordinates
 */
const calculateScrollPosition = (centerX, centerY, fieldElement, fieldSize, tileSize) => {
  const { clientWidth: viewWidth, clientHeight: viewHeight } = fieldElement;
  const { minX, minY, maxX, maxY } = fieldSize;
  
  // Calculate ideal scroll positions
  const idealScrollLeft = centerX - (viewWidth / 2);
  const idealScrollTop = centerY - (viewHeight / 2);
  
  // Calculate field dimensions with padding
  const fieldWidth = (maxX - minX + 1) * tileSize;
  const fieldHeight = (maxY - minY + 1) * tileSize;
  
  // Calculate maximum scroll boundaries with padding
  const maxScrollLeft = Math.max(0, fieldWidth + (FIELD_PADDING * 2) - viewWidth);
  const maxScrollTop = Math.max(0, fieldHeight + (FIELD_PADDING * 2) - viewHeight);
  
  return {
    left: Math.min(Math.max(0, idealScrollLeft + FIELD_PADDING), maxScrollLeft),
    top: Math.min(Math.max(0, idealScrollTop + FIELD_PADDING), maxScrollTop)
  };
};

/**
 * Smooth scroll to position
 * @param {HTMLElement} element - Element to scroll
 * @param {{left: number, top: number}} position - Scroll position
 */
const smoothScrollTo = (element, position) => {
  element.scrollTo({
    ...position,
    behavior: 'smooth'
  });
};

// Main export functions

/**
 * Centers the view on all available places for player moves or tile placements
 * @param {Object} gameData - The game data object
 * @param {Function} centerViewOnMiddle - Function to center view on the middle of the field
 * @param {number} tileSize - The size of each tile in pixels
 */
export const centerViewOnAvailablePlaces = (gameData, centerViewOnMiddle, tileSize) => {
  if (!gameData?.state?.availablePlaces) {
    console.log('No available places data');
    return;
  }

  const fieldElement = getFieldElement();
  if (!fieldElement) {
    console.log('Field element not found');
    return;
  }

  // Get all available places
  const places = [
    ...(gameData.state.availablePlaces.moveTo ?? []),
    ...(gameData.state.availablePlaces.placeTile ?? [])
  ];

  if (places.length === 0) {
    console.log('No available places found');
    centerViewOnMiddle();
    return;
  }

  // Parse positions and filter out invalid ones
  const coordinates = places
    .map(parsePosition)
    .filter(Boolean);

  if (coordinates.length === 0) {
    console.log('No valid coordinates found');
    centerViewOnMiddle();
    return;
  }

  const bounds = calculateBounds(coordinates);
  const fieldSize = gameData.field?.size ?? { minX: 0, minY: 0, maxX: 0, maxY: 0 };
  
  // Get center coordinates in pixels
  const centerX = ((bounds.minX + bounds.maxX) / 2 - fieldSize.minX) * tileSize;
  const centerY = ((bounds.minY + bounds.maxY) / 2 - fieldSize.minY) * tileSize;

  const scrollPosition = calculateScrollPosition(centerX, centerY, fieldElement, fieldSize, tileSize);
  smoothScrollTo(fieldElement, scrollPosition);

  console.log('Centered view on available places', { 
    availablePlaces: bounds, 
    centerPx: { x: centerX, y: centerY },
    scroll: scrollPosition
  });
};

/**
 * Check if any player is at a specific position
 * @param {Object} field - The game field object
 * @param {string} position - The position to check
 * @returns {boolean} - True if any player is at the position
 */
export const playerIsAtPosition = (field, position) => {
  if (!field?.playerPositions || !position) return false;
  
  return Object.values(field.playerPositions).includes(position);
};

/**
 * Get the player ID at a specific position
 * @param {Object} field - The game field object
 * @param {string} position - The position to check
 * @returns {string|null} - The player ID at the position, or null if no player found
 */
export const getPlayerIdAtPosition = (field, position) => {
  if (!field?.playerPositions || !position) return null;

  return Object.entries(field.playerPositions)
    .find(([, playerPosition]) => playerPosition === position)?.[0] ?? null;
};

/**
 * Get all player IDs at a specific position
 * @param {Object} field - The game field object
 * @param {string} position - The position to check
 * @returns {string[]} - Array of player IDs at the position
 */
export const getAllPlayerIdsAtPosition = (field, position) => {
  if (!field?.playerPositions || !position) return [];

  return Object.entries(field.playerPositions)
    .filter(([, playerPosition]) => playerPosition === position)
    .map(([playerId]) => playerId);
};

/**
 * Check if a position is a specific player's position
 * @param {Object} field - The game field object
 * @param {string} position - The position to check
 * @param {string} playerId - The player ID to check
 * @returns {boolean} - True if the position is the player's position
 */
export const isPlayerPosition = (field, position, playerId) => {
  if (!field?.playerPositions || !position || !playerId) return false;
  
  return field.playerPositions[playerId] === position;
};

/**
 * Process available places from string format ('x,y') to object format ({x, y})
 * @param {Object} gameData - The game data object
 * @param {string} [placeType='all'] - Type of places to return: 'all', 'moveTo', or 'placeTile'
 * @returns {Array} - Array of processed places with x,y coordinates
 */
export const getProcessedAvailablePlaces = (gameData, placeType = 'all') => {
  if (!gameData) return [];

  // Get places based on source and type
  const getPlacesFromSource = () => {
    if (gameData.field?.availablePlaces) {
      return gameData.field.availablePlaces;
    }
    
    if (gameData.state?.availablePlaces) {
      const { moveTo = [], placeTile = [] } = gameData.state.availablePlaces;
      
      switch (placeType) {
        case 'moveTo': return moveTo;
        case 'placeTile': return placeTile;
        default: return [...moveTo, ...placeTile];
      }
    }
    
    return [];
  };

  const places = getPlacesFromSource();
  
  return places
    .map(place => {
      const coords = parsePosition(place);
      if (!coords) {
        console.error('Invalid place format:', place);
        return null;
      }
      return coords;
    })
    .filter(Boolean);
}; 

/**
 * Center view on the current player's position
 * @param {Object} gameData - The game data object
 * @param {Function} centerViewOnMiddle - Function to center view on the middle of the field
 * @param {Array} processedTiles - Array of processed tiles
 * @param {Function} centerViewOnTile - Function to center view on a specific tile
 */
export const centerViewOnCurrentPlayer = (gameData, centerViewOnMiddle, processedTiles, centerViewOnTile) => {
  if (!gameData?.field?.playerPositions || !gameData?.state) return;

  const currentPlayerInGame = gameData.state.currentPlayerId;
  if (!currentPlayerInGame) {
    centerViewOnMiddle();
    return;
  }

  const playerPosition = gameData.field.playerPositions[currentPlayerInGame];
  if (!playerPosition) {
    centerViewOnMiddle();
    return;
  }

  const coords = parsePosition(playerPosition);
  if (!coords) {
    centerViewOnMiddle();
    return;
  }

  console.log('Player position found at:', coords.x, coords.y);

  // Find existing tile or create temporary one
  const playerTile = processedTiles.find(tile => 
    tile.x === coords.x && tile.y === coords.y
  ) ?? { ...coords, position: playerPosition };

  centerViewOnTile(playerTile);
  console.log('Centered view on current player at position:', playerPosition);
};

/**
 * Scroll the field view to center on a specific position
 * @param {number} x - The x coordinate to scroll to
 * @param {number} y - The y coordinate to scroll to
 * @param {Object} gameData - The game data object containing field size information
 * @param {number} tileSize - The size of each tile in pixels
 */
export const scrollToPosition = (x, y, gameData, tileSize) => {
  const fieldElement = getFieldElement();
  if (!fieldElement) return;

  const fieldSize = {
    minX: gameData?.field?.size?.minX ?? 0,
    minY: gameData?.field?.size?.minY ?? 0,
    maxX: gameData?.field?.size?.maxX ?? 0,
    maxY: gameData?.field?.size?.maxY ?? 0
  };

  // Calculate tile's center position in pixels
  const tileX = (x - fieldSize.minX) * tileSize;
  const tileY = (y - fieldSize.minY) * tileSize;
  const tileCenterX = tileX + (tileSize / 2);
  const tileCenterY = tileY + (tileSize / 2);

  console.log('Centering on position:', x, y, {
    fieldSize,
    tilePosition: { x: tileX, y: tileY },
    tileCenterPx: { x: tileCenterX, y: tileCenterY }
  });

  const scrollPosition = calculateScrollPosition(tileCenterX, tileCenterY, fieldElement, fieldSize, tileSize);
  smoothScrollTo(fieldElement, scrollPosition);

  console.log('Applied scroll position:', scrollPosition);
};

// Action handlers for handlePlaceClick
const handlePlayerMovement = async (params) => {
  const { 
    position, gameData, currentPlayerId, gameApi, gameId, currentTurnId,
    loadingState, battleState, updateGameDataSelectively, centerViewOnTile
  } = params;
  
  const { loading, loadingStatus, error } = loadingState;
  const { battleInfo, showBattleReportModal } = battleState;

  // Check if a request is already in progress
  if (isRequestInProgress) {
    console.log('Request already in progress, ignoring click');
    return;
  }

  try {
    isRequestInProgress = true;
    loading.value = true;
    loadingStatus.value = 'Moving player...';

    const currentPosition = gameData.field.playerPositions[currentPlayerId.value];
    
    const moveResponse = await gameApi.movePlayer({
      gameId,
      fromPosition: currentPosition,
      toPosition: position,
      playerId: currentPlayerId.value,
      turnId: currentTurnId
    });

    if (moveResponse?.battleInfo) {
      console.log('Battle occurred:', moveResponse.battleInfo);
      battleInfo.value = moveResponse.battleInfo;
      showBattleReportModal.value = true;
      loading.value = false;
      return moveResponse;
    }

    // Update player position locally
    if (gameData.field.playerPositions) {
      gameData.field.playerPositions[currentPlayerId.value] = position;
    }
    
    // Don't automatically end turn after move - the turn will be ended:
    // - By the calling component if there's an item to pick up
    // - By the player after they make a choice about the item
    // - Automatically if there's nothing to pick up
    
    const updatedGameState = await gameApi.getGame(gameId);
    updateGameDataSelectively(updatedGameState);

    const coords = parsePosition(position);
    if (coords) {
      centerViewOnTile(coords);
    }
    
    // Check if there's an item at the destination position
    const items = updatedGameState?.field?.items || {};
    const itemAtPosition = items[position];
    
    if (itemAtPosition && itemAtPosition.guardDefeated) {
      console.log('Item found at destination after move:', itemAtPosition);
      
      // Create itemInfo response similar to what backend should return
      const itemInfoResponse = {
        ...moveResponse,
        itemInfo: {
          position: position,
          item: itemAtPosition,
          requiresKey: itemAtPosition.type === 'chest' || itemAtPosition.type === 'ruby_chest',
          hasKey: updatedGameState?.state?.players?.[currentPlayerId.value]?.inventory?.keys?.length > 0
        }
      };
      
      // Return the enhanced response with itemInfo
      return itemInfoResponse;
    }
    
    // Return the move response so the caller can check for itemInfo
    return moveResponse;
  } catch (err) {
    console.error('Failed to move player:', err);
    
    // Check if the error is due to inventory being full
    if (err.response && err.response.data && err.response.data.error && 
        err.response.data.error.includes('Inventory')) {
      console.log('Move failed due to inventory full - checking for item at destination');
      
      // Get the updated game state to check if player moved
      try {
        const updatedGameState = await gameApi.getGame(gameId);
        updateGameDataSelectively(updatedGameState);
        
        // Check if player actually moved to the destination
        const currentPos = updatedGameState?.field?.playerPositions?.[currentPlayerId.value];
        if (currentPos === position) {
          console.log('Player moved successfully despite error - checking for item');
          
          // Check if there's an item at the position
          const items = updatedGameState?.field?.items || {};
          const itemAtPosition = items[position];
          
          if (itemAtPosition && itemAtPosition.guardDefeated) {
            console.log('Item found at destination, showing pickup dialog');
            
            // Create itemInfo response
            const itemInfoResponse = {
              itemInfo: {
                position: position,
                item: itemAtPosition,
                requiresKey: itemAtPosition.type === 'chest' || itemAtPosition.type === 'ruby_chest',
                hasKey: updatedGameState?.state?.players?.[currentPlayerId.value]?.inventory?.keys?.length > 0
              }
            };
            
            loading.value = false;
            return itemInfoResponse;
          }
        }
      } catch (updateErr) {
        console.error('Failed to get updated game state:', updateErr);
      }
    }
    
    error.value = `Failed to move player: ${err.message}`;
  } finally {
    isRequestInProgress = false;
    loading.value = false;
    loadingStatus.value = '';
  }
};

const handleTilePlacement = async (params) => {
  const {
    position, gameData, currentPlayerId, gameApi, gameId, currentTurnId,
    tileState, battleState, updateGameDataSelectively, centerViewOnTile, tileUtils
  } = params;
  
  const { pickedTileId, pickedTile, ghostTileOrientation } = tileState;
  const { battleInfo, showBattleReportModal } = battleState;
  const { getTileOrientationChar, getTileOrientationSymbol } = tileUtils;

  // Check if a request is already in progress
  if (isRequestInProgress) {
    console.log('Request already in progress, ignoring click');
    return;
  }

  try {
    isRequestInProgress = true;
    
    await gameApi.placeTile({
      gameId,
      tileId: pickedTileId.value,
      fieldPlace: position,
      playerId: currentPlayerId.value,
      turnId: currentTurnId
    });

    const coords = parsePosition(position);
    if (!coords) return;

    const newTile = {
      position,
      tileId: pickedTileId.value,
      ...coords,
      isPlacingAnimation: true,
      orientationChar: getTileOrientationChar(position, gameData),
      isRoom: pickedTile.value.room
    };

    // Update field size if needed
    if (gameData.field?.size) {
      const { size } = gameData.field;
      size.minX = Math.min(size.minX, coords.x);
      size.maxX = Math.max(size.maxX, coords.x);
      size.minY = Math.min(size.minY, coords.y);
      size.maxY = Math.max(size.maxY, coords.y);
    }

    gameData.field.tiles.push(newTile);

    // Update tile orientations map
    if (gameData.field.tileOrientations) {
      gameData.field.tileOrientations[position] = getTileOrientationSymbol(
        ghostTileOrientation.value, 
        pickedTile.value.room
      );
    }

    // Move player to the newly placed tile
    const currentPosition = gameData.field.playerPositions[currentPlayerId.value];
    const moveResponse = await gameApi.movePlayer({
      gameId,
      fromPosition: currentPosition,
      toPosition: position,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      isTilePlacementMove: true  // This flag prevents turn from ending after tile placement
    });

    // Handle battle after tile placement
    if (moveResponse?.battleInfo) {
      console.log('Battle occurred after placing tile:', moveResponse.battleInfo);
      battleInfo.value = moveResponse.battleInfo;
      showBattleReportModal.value = true;
      
      if (gameData.field.playerPositions) {
        gameData.field.playerPositions[currentPlayerId.value] = position;
      }
      
      clearTileState(tileState);
      centerViewOnTile(coords);
      return;
    }

    // Update player position locally
    if (gameData.field.playerPositions) {
      gameData.field.playerPositions[currentPlayerId.value] = position;
    }

    // DO NOT end turn after tile placement - player can continue moving
    // Turn only ends on specific actions like combat or item pickup
    
    const updatedGameState = await gameApi.getGame(gameId);
    updateGameDataSelectively(updatedGameState);

    clearTileState(tileState);
    centerViewOnTile(coords);

    // Remove animation class after animation completes
    setTimeout(() => {
      const tileIndex = gameData.field.tiles.findIndex(t => t.position === position);
      if (tileIndex >= 0) {
        gameData.field.tiles[tileIndex].isPlacingAnimation = false;
      }
    }, TILE_ANIMATION_DURATION);
    
    // Return response indicating tile was placed (exploration continues)
    return { tilePlaced: true };
  } catch (err) {
    console.error('Failed to place tile:', err);
    throw err;
  } finally {
    isRequestInProgress = false;
  }
};

const handleTilePicking = async (params) => {
  const {
    position, gameData, currentPlayerId, gameApi, gameId, currentTurnId,
    tileState, tileUtils, loadingState
  } = params;
  
  const { pickedTileId, pickedTile, ghostTilePosition, isPlacingTile, ghostTileOrientation } = tileState;
  const { generateUUID, getRequiredOpenSide, handleInitialTileOrientation, cancelTilePlacement } = tileUtils;
  const { error } = loadingState;

  // Check if a request is already in progress
  if (isRequestInProgress) {
    console.log('Request already in progress, ignoring click');
    return;
  }
  
  // Prevent picking a new tile if one is already picked
  if (pickedTileId.value || pickedTile.value) {
    console.log('A tile is already picked, cannot pick another one');
    error.value = 'You already have a tile picked. Place it or press Escape to cancel.';
    return;
  }
  
  // Check if the deck is empty
  if (gameData?.state?.deck?.isEmpty) {
    console.log('Deck is empty, cannot pick more tiles');
    error.value = 'No more tiles available in the deck';
    return;
  }

  try {
    isRequestInProgress = true;
    
    const tileId = generateUUID();
    const requiredOpenSide = getRequiredOpenSide(position, gameData, currentPlayerId.value);
    
    if (requiredOpenSide === null) {
      error.value = 'Could not determine required open side';
      return;
    }

    const response = await gameApi.pickTile({
      gameId,
      tileId,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      requiredOpenSide,
    });

    // Set initial tile data
    pickedTileId.value = tileId;
    pickedTile.value = response.tile;
    ghostTilePosition.value = position;
    // Set initial orientation for the ghost tile to display
    if (response.tile && response.tile.orientation) {
      const initialOrientation = parseOrientationString(response.tile.orientation);
      console.log('Setting initial ghost tile orientation:', {
        orientationString: response.tile.orientation,
        parsed: initialOrientation,
        position
      });
      ghostTileOrientation.value = initialOrientation;
    }
    // Set isPlacingTile immediately to show the ghost tile during rotation
    isPlacingTile.value = true;

    // Try to find a valid initial orientation
    const validOrientation = await handleInitialTileOrientation(position);

    if (!validOrientation) {
      cancelTilePlacement();
      error.value = 'No valid orientation found - tile must have a direct connection to your current tile';
      return;
    }

    console.log('Picked tile:', response.tile);
    
    // Return a response indicating tile was picked (not a turn-ending action)
    return { tilePicked: true };
  } catch (err) {
    console.error('Failed to pick tile:', err);
    error.value = `Failed to pick tile: ${err.message}`;
  } finally {
    isRequestInProgress = false;
  }
};

/**
 * Clear tile state after placement
 * @param {Object} tileState - Tile state object
 */
const clearTileState = (tileState) => {
  const { ghostTilePosition, pickedTileId, pickedTile, isPlacingTile } = tileState;
  
  ghostTilePosition.value = null;
  pickedTileId.value = null;
  pickedTile.value = null;
  isPlacingTile.value = false;
};

/**
 * Validate player turn and sync player IDs
 * @param {Object} params - Validation parameters
 * @returns {boolean} - True if validation passes
 */
const validatePlayerTurn = (params) => {
  const { isPlayerTurn, gameData, currentPlayerId, autoSwitchPlayer } = params;
  
  if (!isPlayerTurn) {
    console.log('Not your turn');
    return false;
  }

  // Ensure player ID sync
  if (gameData?.state?.currentPlayerId !== currentPlayerId.value) {
    console.error('Player ID mismatch: UI player does not match game state player');
    console.log('UI player:', currentPlayerId.value);
    console.log('Game state player:', gameData?.state?.currentPlayerId);

    autoSwitchPlayer();

    if (gameData?.state?.currentPlayerId !== currentPlayerId.value) {
      console.error('Failed to align player IDs, aborting action');
      return false;
    }
  }

  return true;
};

/**
 * Handle clicking on an available place in the field
 * @param {Object} params - Parameters object
 * @returns {Promise<void>}
 */
export const handlePlaceClick = async (params) => {
  const {
    position, gameData, currentPlayerId, tileState, tileUtils, loadingState
  } = params;
  
  const { error } = loadingState;
  const { pickedTileId, pickedTile } = tileState;
  const { isValidOrientation } = tileUtils;

  // Early return if request is already in progress
  if (isRequestInProgress) {
    console.log('🚫 Request already in progress, ignoring click on position:', position);
    return;
  }

  // Validate player turn
  if (!validatePlayerTurn(params)) {
    return;
  }

  // Get current turn ID
  const currentTurnId = gameData?.state?.currentTurnId;
  if (!currentTurnId) {
    console.error('No current turn ID found in game state');
    return;
  }

  // Determine action type
  const existingTile = gameData?.field?.tiles?.find(tile => tile.position === position);
  const isMoveTo = gameData?.state?.availablePlaces?.moveTo?.includes(position);
  const isPlaceTile = gameData?.state?.availablePlaces?.placeTile?.includes(position);
  const isDeckEmpty = gameData?.state?.deck?.isEmpty;
  
  // Fix: Allow empty spots to be used for tile placement even if not in placeTile array
  // but only if they're in moveTo array and the deck is not empty
  const canPlaceTile = isPlaceTile || 
                      (isMoveTo && 
                       !existingTile && 
                       !isDeckEmpty && 
                       gameData?.state?.availablePlaces?.placeTile?.length === 0);

  console.log('🎯 Processing click on position:', position, {
    existingTile: !!existingTile,
    isMoveTo,
    isPlaceTile,
    canPlaceTile,
    hasPicked: !!pickedTileId.value,
    isDeckEmpty
  });

  const actionParams = { ...params, currentTurnId };

  // Handle different action types
  if (existingTile && isMoveTo) {
    const moveResponse = await handlePlayerMovement(actionParams);
    return moveResponse; // Return the response so caller can check for itemInfo
  } else if (pickedTileId.value && pickedTile.value && canPlaceTile && !existingTile) {
    // Validate tile orientation
    if (!isValidOrientation(position, pickedTile.value.orientation, gameData, currentPlayerId.value)) {
      console.log('Invalid tile orientation for this position');
      error.value = 'Invalid tile orientation - must have a direct connection to your current tile';
      return;
    }
    
    try {
      const placeResponse = await handleTilePlacement(actionParams);
      return placeResponse; // Return response from tile placement
    } catch (err) {
      error.value = `Failed to place tile: ${err.message}`;
    }
  } else if (!pickedTileId.value && !existingTile && !isDeckEmpty && isMoveTo) {
    // Modified condition to allow picking tiles on any valid movement space when deck isn't empty
    const pickResponse = await handleTilePicking(actionParams);
    return pickResponse; // Return the response so caller knows a tile was picked
  } else if (pickedTileId.value && !existingTile && isMoveTo) {
    // If we have a picked tile but clicked on a non-placeable movement spot, show error
    console.log('Cannot place tile here - not a valid placement location');
    error.value = 'You cannot place a tile here. Click on a valid placement location or press Escape to cancel.';
    return;
  } else if (!isMoveTo && !existingTile && !isDeckEmpty) {
    console.log('Debug place tile logic:', {
      position,
      isMoveTo,
      isPlaceTile,
      availablePlaces: gameData?.state?.availablePlaces,
      fieldPlaceExists: !!existingTile,
      isDeckEmpty
    });
  }
};

// Function to determine if an item is worth picking up based on player inventory
export const isItemWorthPickingUp = (item, playerInventory) => {
  if (!item || !playerInventory) return false;
  
  const itemType = item.type;
  
  // Handle keys - if player already has a key, don't prompt
  if (itemType === 'key') {
    return playerInventory.keys?.length === 0;
  }
  
  // Handle weapons (daggers, swords, axes)
  if (['dagger', 'sword', 'axe'].includes(itemType)) {
    // Get the damage value of this weapon
    const itemDamage = item.damage || (itemType === 'dagger' ? 1 : (itemType === 'sword' ? 2 : 3));
    
    // If player has 2 or more weapons, only prompt if this one is better than what they have
    if (playerInventory.weapons?.length >= 2) {
      // Sort weapons by damage in descending order
      const sortedWeapons = [...playerInventory.weapons].sort((a, b) => {
        const aDamage = a.damage || (a.type === 'dagger' ? 1 : (a.type === 'sword' ? 2 : 3));
        const bDamage = b.damage || (b.type === 'dagger' ? 1 : (b.type === 'sword' ? 2 : 3));
        return bDamage - aDamage;
      });
      
      // Only prompt if this weapon is better than the weakest one they have
      const weakestWeaponDamage = sortedWeapons.length > 0 ? 
        (sortedWeapons[sortedWeapons.length - 1].damage || 
         (sortedWeapons[sortedWeapons.length - 1].type === 'dagger' ? 1 : 
          (sortedWeapons[sortedWeapons.length - 1].type === 'sword' ? 2 : 3))) : 0;
          
      return itemDamage > weakestWeaponDamage;
    }
    
    // If player has fewer than 2 weapons, always prompt
    return true;
  }
  
  // Handle spells
  if (['fireball', 'teleport'].includes(itemType)) {
    // If player has 3 or more spells, only prompt if this one is better than what they have
    if (playerInventory.spells?.length >= 3) {
      // For simplicity, we'll just check if they have this exact spell type already
      return !playerInventory.spells.some(spell => spell.type === itemType);
    }
    
    // If player has fewer than 3 spells, always prompt
    return true;
  }
  
  // Handle chests - only prompt if player has a key
  if (['chest', 'ruby_chest'].includes(itemType)) {
    return playerInventory.keys?.length > 0;
  }
  
  // Always prompt for other treasure types
  return true;
}

/**
 * Check if a field place already has a tile
 * @param {string} position - Field place position string (e.g. "0,0")
 * @param {Object} gameData - Game data object with field and tiles
 * @returns {boolean} - True if the field place already has a tile
 */
export const isFieldPlaceAlreadyTaken = (position, gameData) => {
  if (!gameData?.field?.tiles) return false;
  return gameData.field.tiles.some(tile => tile.position === position);
};
