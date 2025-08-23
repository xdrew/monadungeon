/**
 * Gets the tile orientation character from the game data
 * @param {string} position - The position string (e.g. "0,0")
 * @param {Object} gameData - The game data containing field information
 * @returns {string} The orientation character for the tile
 */
export const getTileOrientationChar = (position, gameData) => {
  // The API should provide tileOrientations as part of the field data
  if (gameData?.field?.tileOrientations && position) {
    const orientation = gameData.field.tileOrientations[position];
    if (orientation) {
      return orientation;
    }
  }

  // For debugging
  console.log('No orientation found for position:', position);

  // Default to crossroads if no orientation data is available
  return '╋';
}; 

/**
 * Checks if a tile at the given position is a room tile
 * @param {string} position - The position string (e.g. "0,0")
 * @param {Object} gameData - The game data containing field information
 * @returns {boolean} True if the tile is a room tile, false otherwise
 */
export const isRoomTile = (position, gameData) => {
  // First check the roomFieldPlaces array if available
  if (gameData?.field?.roomFieldPlaces && position) {
    if (gameData.field.roomFieldPlaces.includes(position)) {
      return true;
    }
  }
  
  // Also check the orientation character as a fallback
  // Room tiles use double-line box drawing characters
  if (gameData?.field?.tileOrientations && position) {
    const orientationChar = gameData.field.tileOrientations[position];
    if (orientationChar) {
      // Room characters: ╬ ╠ ╣ ╦ ╩ ║ ═ ╔ ╗ ╝ ╚
      const roomChars = ['╬', '╠', '╣', '╦', '╩', '║', '═', '╔', '╗', '╝', '╚'];
      return roomChars.includes(orientationChar);
    }
  }
  
  return false; // Default
}; 

/**
 * Checks if a tile at the given position has an item and returns it
 * @param {string} position - The position string (e.g. "0,0")
 * @param {Object} gameData - The game data containing field information
 * @returns {Object|boolean} The item object if found, false otherwise
 */
export const hasTileItem = (position, gameData) => {
  // The API should provide items as part of the field data
  if (gameData?.field?.items && position) {
    // If the item exists for this position, return the item object instead of just a boolean
    return gameData.field.items[position] || false;
  }
  return false; // Default
}; 

/**
 * Map orientation character to CSS class
 * @param {string} char - The orientation character
 * @param {boolean} isRoom - Whether the tile is a room tile
 * @returns {string} The CSS class corresponding to the orientation
 */
export const getTileOrientationClass = (char, isRoom = false) => {
  // Process orientation strings first (TBLR format)
  if (typeof char === 'string') {
    // Room or corridor orientation based on openings
    if (char.includes('T') && char.includes('R') && char.includes('B') && char.includes('L')) {
      return 'crossroads';    // All openings
    }
    if (char.includes('T') && char.includes('B') && !char.includes('L') && !char.includes('R')) {
      return 'vertical';      // Vertical only
    }
    if (char.includes('L') && char.includes('R') && !char.includes('T') && !char.includes('B')) {
      return 'horizontal';    // Horizontal only
    }
    if (char.includes('T') && char.includes('R') && !char.includes('B') && !char.includes('L')) {
      return 'bottom-right';  // Top-right corner (bottom-right opening)
    }
    if (char.includes('T') && !char.includes('R') && !char.includes('B') && char.includes('L')) {
      return 'bottom-left';   // Top-left corner (bottom-left opening)
    }
    if (!char.includes('T') && !char.includes('R') && char.includes('B') && char.includes('L')) {
      return 'top-left';      // Bottom-left corner (top-left opening)
    }
    if (!char.includes('T') && char.includes('R') && char.includes('B') && !char.includes('L')) {
      return 'top-right';     // Bottom-right corner (top-right opening)
    }
    if (char.includes('T') && char.includes('L') && char.includes('R') && !char.includes('B')) {
      return 't-up';          // T-up junction
    }
    if (!char.includes('T') && char.includes('L') && char.includes('B') && char.includes('R')) {
      return 't-down';        // T-down junction
    }
    if (char.includes('T') && !char.includes('L') && char.includes('B') && char.includes('R')) {
      return 't-left';        // T-left junction
    }
    if (char.includes('T') && char.includes('L') && char.includes('B') && !char.includes('R')) {
      return 't-right';       // T-right junction
    }
  }

  // Handle specific Unicode characters directly
  // Room characters (double line)
  if (char === '║') return 'vertical';         // Vertical room
  if (char === '═') return 'horizontal';       // Horizontal room
  if (char === '╔') return 'top-right';        // Top-right room corner
  if (char === '╗') return 'top-left';         // Top-left room corner
  if (char === '╚') return 'bottom-right';     // Bottom-right room corner
  if (char === '╝') return 'bottom-left';      // Bottom-left room corner
  if (char === '╠') return 't-right';          // T-right room junction
  if (char === '╣') return 't-left';           // T-left room junction
  if (char === '╦') return 't-down';           // T-down room junction
  if (char === '╩') return 't-up';             // T-up room junction
  if (char === '╬') return 'crossroads';       // Crossroads room

  // Corridor characters (single line)
  if (char === '┃') return 'vertical';         // Vertical corridor
  if (char === '━') return 'horizontal';       // Horizontal corridor
  if (char === '┏') return 'top-right';        // Top-right corridor corner
  if (char === '┓') return 'top-left';         // Top-left corridor corner
  if (char === '┗') return 'bottom-right';     // Bottom-right corridor corner
  if (char === '┛') return 'bottom-left';      // Bottom-left corridor corner
  if (char === '┣') return 't-right';          // T-right corridor junction
  if (char === '┫') return 't-left';           // T-left corridor junction
  if (char === '┳') return 't-down';           // T-down corridor junction
  if (char === '┻') return 't-up';             // T-up corridor junction
  if (char === '╋') return 'crossroads';       // Crossroads corridor

  // If we couldn't determine a specific orientation
  if (isRoom) {
    // Default room to crossroads
    return 'room-crossroads';
  } else {
    // Default corridor to crossroads
    return 'corridor-crossroads';
  }
};

/**
 * Process tiles to add additional information for rendering
 * @param {Object} gameData - The game data containing field information
 * @param {Function} playerIsAtPosition - Function to check if a player is at a position
 * @param {Function} getPlayerIdAtPosition - Function to get the player ID at a position
 * @param {Function} getAllPlayerIdsAtPosition - Function to get all player IDs at a position
 * @param {boolean} limitLogging - Whether to limit console logging (useful for large fields)
 * @returns {Array} Processed tiles with additional rendering information
 */
export const processTiles = (
  gameData, 
  playerIsAtPosition, 
  getPlayerIdAtPosition, 
  getAllPlayerIdsAtPosition = null,
  limitLogging = true
) => {
  if (!gameData || !gameData.field || !gameData.field.tiles) return [];

  return gameData.field.tiles.map(tile => {
    if (!tile || !tile.position) return null;

    try {
      // Get orientation character from the tileOrientations map if available
      const position = tile.position;
      const orientationChar = getTileOrientationChar(position, gameData);
      const itemResult = hasTileItem(position, gameData);
      const item = itemResult === false ? null : itemResult; // Convert false to null for prop compatibility
      const hasItem = !!item; // Convert item object to boolean for hasItem flag
      const [x, y] = tile.position.split(',').map(Number);
      const isRoom = isRoomTile(position, gameData);

      if (isNaN(x) || isNaN(y)) {
        console.error('Invalid position format:', tile.position);
        return null;
      }

      // Determine orientation class with isRoom flag
      let orientationClass = getTileOrientationClass(orientationChar, isRoom);

      // Log orientation information for debugging
      if (limitLogging && gameData.field.tiles.length < 30) { // Limit logging for larger fields
        console.log('Tile orientation at', position, ':', {
          orientationChar,
          orientationClass,
          isRoom
        });
      }

      // Determine if this is the starting tile
      const isStartingTile = x === 0 && y === 0;

      // For debugging - log detailed info for a small number of tiles
      if (limitLogging && gameData.field.tiles.length < 20) { // Limit logging for larger fields
        console.log('Processed tile:', { 
          position, 
          orientationChar, 
          orientationClass,
          isRoom,
          isStartingTile
        });
      }

      // Check if there's a player at this position
      const hasPlayer = playerIsAtPosition ? playerIsAtPosition(position) : false;
      const playerId = getPlayerIdAtPosition ? getPlayerIdAtPosition(position) : null;
      
      // Get all players at this position for multi-player support
      const allPlayerIds = getAllPlayerIdsAtPosition ? getAllPlayerIdsAtPosition(position) : (playerId ? [playerId] : []);
      
      // Get features for this tile
      const features = tile.features || [];
      
      // Check if tile has healing fountain
      const hasHealingFountain = features.includes('healing_fountain');
      
      // Check if tile has teleportation gate
      const hasTeleportationGate = features.includes('teleportation_gate');

      return {
        ...tile,
        x,
        y,
        orientationChar,
        isRoom,
        isStartingTile,
        orientation: orientationClass,
        hasItem,
        item, // Include the actual item data
        hasPlayer,
        playerId, // Keep for backward compatibility
        allPlayerIds, // New field for multiple players
        features, // Include tile features
        hasHealingFountain, // Flag for healing fountain
        hasTeleportationGate // Flag for teleportation gate
      };
    } catch (error) {
      console.error('Error processing tile:', tile, error);
      return null;
    }
  }).filter(Boolean); // Remove null entries
}; 

/**
 * Map orientation character to visual symbol based on tile type
 * @param {string|character} char - The orientation character or string in TBLR format
 * @param {boolean} isRoom - Whether the tile is a room tile
 * @returns {string} The appropriate box drawing character
 */
export const getTileOrientationSymbol = (char, isRoom = false) => {
  // If it's already a valid box drawing character, return it
  if (typeof char === 'string' && char.length === 1 && /[╋║═╔╗╝╚╠╣╦╩╬┃━┏┓┛┗┣┫┳┻]/.test(char)) {
    return char;
  }

  // If it's in TBLR format, convert it to binary format first
  if (typeof char === 'string' && (char.includes('T') || char.includes('B') || char.includes('L') || char.includes('R'))) {
    const binary = [
      char.includes('T') ? '1' : '0',
      char.includes('R') ? '1' : '0',
      char.includes('B') ? '1' : '0',
      char.includes('L') ? '1' : '0'
    ].join('');
    return getSymbolFromBinary(binary, isRoom);
  }

  // Default symbols if no match
  return isRoom ? '╬' : '╋';
};

/**
 * Convert binary orientation format to appropriate box drawing symbol
 * @param {string} binary - Binary string representing open sides (TRBL format, e.g. '1111')
 * @param {boolean} isRoom - Whether the tile is a room tile
 * @returns {string} The appropriate box drawing character
 */
const getSymbolFromBinary = (binary, isRoom = false) => {
  const mapping = {
    '1111': ['╬', '╋'], // All sides open (crossroad)
    '1010': ['║', '┃'], // Top and bottom open (vertical straight)
    '0101': ['═', '━'], // Left and right open (horizontal straight)
    '0011': ['╗', '┓'], // Right and bottom open (top-left corner)
    '0110': ['╔', '┏'], // Bottom and left open (top-right corner)
    '1001': ['╝', '┛'], // Top and left open (bottom-right corner)
    '1100': ['╚', '┗'], // Top and right open (bottom-left corner)
    '1110': ['╠', '┣'], // Top, right, bottom open (T-junction right)
    '1011': ['╣', '┫'], // Top, bottom, left open (T-junction left)
    '0111': ['╦', '┳'], // Right, bottom, left open (T-junction down)
    '1101': ['╩', '┻']  // Top, right, left open (T-junction up)
  };

  return mapping[binary]?.[isRoom ? 0 : 1] || (isRoom ? '╬' : '╋');
}; 

/**
 * Parse orientation string from API into TBLR format
 * @param {string} orientationString - The orientation string in "true,true,false,false" format
 * @returns {string} Orientation in TBLR format (e.g. "TR")
 */
export const parseOrientationString = (orientationString) => {
  if (!orientationString) return '';
  const [top, right, bottom, left] = orientationString.split(',').map(val => val === 'true');
  let result = '';
  if (top) result += 'T';
  if (right) result += 'R';
  if (bottom) result += 'B';
  if (left) result += 'L';
  return result;
};

/**
 * Check if a tile has an opening in a specific direction
 * @param {string} orientationChar - The orientation character in TBLR format
 * @param {string} direction - The direction to check (T, R, B, or L)
 * @returns {boolean} True if the tile has an opening in the specified direction
 */
export const hasOpening = (orientationChar, direction) => {
  if (!orientationChar) return false;

  // Handle different orientation formats
  if (typeof orientationChar === 'string') {
    // Direct string format (TBLR)
    return orientationChar.includes(direction);
  }

  // Default - no opening
  return false;
}; 

/**
 * Checks if two tile orientations have matching doors on a specific side
 * @param {string|Array} orientation1 - The first orientation (can be string or boolean array)
 * @param {string|Array} orientation2 - The second orientation (can be string or boolean array)
 * @param {string} side - The side to check ('top', 'right', 'bottom', 'left')
 * @returns {boolean} True if the doors match, false otherwise
 */
export const hasMatchingDoors = (orientation1, orientation2, side) => {
  // Convert string orientations (╋, ║, etc.) to boolean arrays
  const convertOrientation = (orientation) => {
    if (typeof orientation === 'string') {
      if (orientation.includes(',')) {
        // Already in true/false format
        return orientation.split(',').map(val => val === 'true');
      }
      // Convert Unicode characters to boolean array [top, right, bottom, left]
      switch (orientation) {
        case '╋': return [true, true, true, true];
        case '║': return [true, false, true, false];
        case '═': return [false, true, false, true];
        case '╗': return [false, false, true, true];
        case '╔': return [false, true, true, false];
        case '╝': return [true, false, false, true];
        case '╚': return [true, true, false, false];
        case '╠': return [true, true, true, false];
        case '╣': return [true, false, true, true];
        case '╦': return [false, true, true, true];
        case '╩': return [true, true, false, true];
        default: return [false, false, false, false];
      }
    }
    return orientation;
  };

  const [top1, right1, bottom1, left1] = convertOrientation(orientation1);
  const [top2, right2, bottom2, left2] = convertOrientation(orientation2);

  switch (side) {
    case 'top': return bottom1 && top2;
    case 'right': return left1 && right2;
    case 'bottom': return top1 && bottom2;
    case 'left': return right1 && left2;
    default: return false;
  }
};

/**
 * Gets the opposite side of a given side
 * @param {string} side - The side to get the opposite of ('top', 'right', 'bottom', 'left')
 * @returns {string|null} The opposite side, or null if the input is invalid
 */
export const getOppositeSide = (side) => {
  switch (side) {
    case 'top': return 'bottom';
    case 'right': return 'left';
    case 'bottom': return 'top';
    case 'left': return 'right';
    default: return null;
  }
};

/**
 * Checks if a side is open in a given orientation
 * @param {string|Array} orientation - The orientation to check (can be string or boolean array)
 * @param {string} side - The side to check ('top', 'right', 'bottom', 'left')
 * @returns {boolean} True if the side is open, false otherwise
 */
export const isOpenedSide = (orientation, side) => {
  // Convert orientation string to boolean array [top, right, bottom, left]
  let openings;

  if (typeof orientation === 'string') {
    if (orientation.includes(',')) {
      // Handle 'true,false,true,false' format
      openings = orientation.split(',').map(val => val === 'true');
    } else {
      // Handle Unicode characters
      switch (orientation) {
        // Double-line characters (rooms)
        case '╬': // Room crossroads
        case '╋': // Corridor crossroads
          openings = [true, true, true, true]; 
          break;
        case '║': // Room vertical
        case '┃': // Corridor vertical
          openings = [true, false, true, false]; 
          break;
        case '═': // Room horizontal
        case '━': // Corridor horizontal
          openings = [false, true, false, true]; 
          break;
        case '╗': // Room top-left corner
        case '┓': // Corridor top-left corner
          openings = [false, false, true, true]; 
          break;
        case '╔': // Room top-right corner
        case '┏': // Corridor top-right corner
          openings = [false, true, true, false]; 
          break;
        case '╝': // Room bottom-left corner
        case '┛': // Corridor bottom-left corner
          openings = [true, false, false, true]; 
          break;
        case '╚': // Room bottom-right corner
        case '┗': // Corridor bottom-right corner
          openings = [true, true, false, false]; 
          break;
        case '╠': // Room T-right junction
        case '┣': // Corridor T-right junction
          openings = [true, true, true, false]; 
          break;
        case '╣': // Room T-left junction
        case '┫': // Corridor T-left junction
          openings = [true, false, true, true]; 
          break;
        case '╦': // Room T-down junction
        case '┳': // Corridor T-down junction
          openings = [false, true, true, true]; 
          break;
        case '╩': // Room T-up junction
        case '┻': // Corridor T-up junction
          openings = [true, true, false, true]; 
          break;
        default: 
          openings = [false, false, false, false];
      }
    }
  }

  // Add debug logging
  console.log('isOpenedSide check:', {
    orientation,
    side,
    openings,
    result: side === 'top' ? openings[0] : 
           side === 'right' ? openings[1] : 
           side === 'bottom' ? openings[2] : 
           side === 'left' ? openings[3] : false
  });

  switch (side) {
    case 'top': return openings[0];
    case 'right': return openings[1];
    case 'bottom': return openings[2];
    case 'left': return openings[3];
    default: return false;
  }
};

/**
 * Gets all adjacent positions for a given position
 * @param {string} position - The position string (e.g. "0,0")
 * @returns {Object} An object with top, right, bottom, and left positions
 */
export const getAllAdjacentPositions = (position) => {
  const [x, y] = position.split(',').map(Number);
  return {
    top: `${x},${y-1}`,
    right: `${x+1},${y}`,
    bottom: `${x},${y+1}`,
    left: `${x-1},${y}`
  };
};

/**
 * Gets the orientation of a tile at the given position
 * @param {string} position - The position string (e.g. "0,0")
 * @param {Object} gameData - The game data containing field information
 * @returns {string|null} The orientation of the tile, or null if not found
 */
export const getTileOrientationAt = (position, gameData) => {
  if (!position) return null;

  // Get orientation directly from the tileOrientations map if available
  if (gameData?.field?.tileOrientations && gameData.field.tileOrientations[position]) {
    return gameData.field.tileOrientations[position];
  }

  // If not in the map, try to find the tile and get its orientation
  const tile = gameData?.field?.tiles?.find(t => t.position === position);
  if (!tile) return null;

  // If the tile exists but no orientation in the map, return a default crossroads
  return '╋';
};

/**
 * Checks if a tile orientation is valid at a given position
 * @param {string} position - The position string (e.g. "0,0")
 * @param {string} orientation - The orientation string
 * @param {Object} gameData - The game data containing field information
 * @param {string} currentPlayerId - The current player ID
 * @returns {boolean} True if the orientation is valid, false otherwise
 */
export const isValidOrientation = (position, orientation, gameData, currentPlayerId) => {
  if (!position || !orientation) return false;

  const playerPos = gameData?.field?.playerPositions?.[currentPlayerId];
  if (!playerPos) {
    console.log('No player position found');
    return false;
  }

  // Get positions as coordinates
  const [x, y] = position.split(',').map(Number);
  const [playerX, playerY] = playerPos.split(',').map(Number);

  // Check if the position is directly adjacent to player
  const isAdjacent = (Math.abs(x - playerX) === 1 && y === playerY) || 
                     (Math.abs(y - playerY) === 1 && x === playerX);

  if (!isAdjacent) {
    console.log('Position is not adjacent to player');
    return false;
  }

  // Determine which side the new tile is relative to the player
  let sideFromPlayer;
  if (y < playerY) sideFromPlayer = 'top';
  else if (y > playerY) sideFromPlayer = 'bottom';
  else if (x < playerX) sideFromPlayer = 'left';
  else if (x > playerX) sideFromPlayer = 'right';

  // Get player's current tile orientation
  const playerTileOrientation = getTileOrientationAt(playerPos, gameData);
  if (!playerTileOrientation) {
    console.log('No player tile orientation found');
    return false;
  }

  // Get the side from new tile back to player
  const sideFromNewTile = getOppositeSide(sideFromPlayer);

  // For a valid connection:
  // 1. Player's tile MUST have an open side in the direction of the new tile
  // 2. New tile MUST have an open side in the direction of the player
  const playerTileHasOpening = isOpenedSide(playerTileOrientation, sideFromPlayer);
  const newTileHasOpening = isOpenedSide(orientation, sideFromNewTile);

  console.log('Checking connection:', {
    position,
    playerPos,
    sideFromPlayer,
    sideFromNewTile,
    playerTileOrientation,
    playerTileHasOpening,
    newTileOrientation: orientation,
    newTileHasOpening,
    isValid: playerTileHasOpening && newTileHasOpening
  });

  return playerTileHasOpening && newTileHasOpening;
};

/**
 * Gets the required open side for a tile at a given position
 * @param {string} position - The position string (e.g. "0,0")
 * @param {Object} gameData - The game data containing field information
 * @param {string} currentPlayerId - The current player ID
 * @returns {number|null} The required open side (0=TOP, 1=RIGHT, 2=BOTTOM, 3=LEFT), or null if not found
 */
export const getRequiredOpenSide = (position, gameData, currentPlayerId) => {
  if (!position) return null;

  const playerPos = gameData?.field?.playerPositions?.[currentPlayerId];
  if (!playerPos) return null;

  // Get positions as coordinates
  const [x, y] = position.split(',').map(Number);
  const [playerX, playerY] = playerPos.split(',').map(Number);

  // Determine which side the new tile is relative to the player
  if (y < playerY) return 2; // BOTTOM
  if (y > playerY) return 0; // TOP
  if (x < playerX) return 1; // RIGHT
  if (x > playerX) return 3; // LEFT

  return null;
};

/**
 * Rotates a ghost tile until a valid orientation is found
 * @param {Object} params - Parameters for rotating the ghost tile
 * @param {string} params.ghostTilePosition - The position of the ghost tile
 * @param {string} params.pickedTileId - The ID of the picked tile
 * @param {Object} params.pickedTile - The picked tile object
 * @param {Object} params.gameData - The game data containing field information
 * @param {string} params.currentPlayerId - The current player ID
 * @param {string} params.gameId - The game ID
 * @param {string} params.currentTurnId - The current turn ID
 * @param {Function} params.rotateTileApi - Function to call the rotate tile API
 * @param {Function} params.onSuccess - Callback function to handle successful rotation
 * @param {Function} params.onError - Callback function to handle rotation error
 * @returns {Promise<boolean>} Promise that resolves to true if a valid orientation was found, false otherwise
 */
export const rotateGhostTile = async ({
  ghostTilePosition,
  pickedTileId,
  pickedTile,
  gameData,
  currentPlayerId,
  gameId,
  currentTurnId,
  rotateTileApi,
  onSuccess,
  onError
}) => {
  if (!pickedTileId || !pickedTile || !ghostTilePosition) return false;

  let attempts = 0;
  let foundValidOrientation = false;

  const requiredOpenSide = getRequiredOpenSide(ghostTilePosition, gameData, currentPlayerId);
  if (requiredOpenSide === null) {
    console.error('Could not determine required open side');
    return false;
  }

  while (attempts < 4 && !foundValidOrientation) {
    try {
      const response = await rotateTileApi({
        tileId: pickedTileId,
        topSide: 3,
        requiredOpenSide,
        gameId,
        playerId: currentPlayerId,
        turnId: currentTurnId
      });

      // Check if this orientation is valid
      const isValid = isValidOrientation(
        ghostTilePosition, 
        response.tile.orientation, 
        gameData, 
        currentPlayerId
      );

      console.log('Rotated tile orientation:', {
        orientation: response.tile.orientation,
        isValid,
        attempt: attempts + 1
      });

      if (isValid) {
        foundValidOrientation = true;
        if (onSuccess) {
          onSuccess(response.tile);
        }
        break;
      }

      // If not valid and not our last attempt, continue rotating
      if (!isValid && attempts < 3) {
        attempts++;
      } else {
        break;
      }
    } catch (err) {
      console.error('Failed to rotate tile:', err);
      if (onError) {
        onError(err);
      }
      break;
    }
  }

  if (!foundValidOrientation) {
    console.log('Could not find valid orientation after rotation attempts');
  }

  return foundValidOrientation;
};

/**
 * Handles the initial orientation of a tile at a given position
 * @param {Object} params - Parameters for handling initial tile orientation
 * @param {string} params.position - The position string (e.g. "0,0")
 * @param {Object} params.pickedTile - The picked tile object
 * @param {string} params.pickedTileId - The ID of the picked tile
 * @param {Object} params.gameData - The game data containing field information
 * @param {string} params.currentPlayerId - The current player ID
 * @param {string} params.gameId - The game ID
 * @param {string} params.currentTurnId - The current turn ID
 * @param {Function} params.rotateTileApi - Function to call the rotate tile API
 * @param {Function} params.onSuccess - Callback function to handle successful orientation
 * @param {Function} params.onError - Callback function to handle orientation error
 * @returns {Promise<boolean>} Promise that resolves to true if a valid orientation was found, false otherwise
 */
export const handleInitialTileOrientation = async ({
  position,
  pickedTile,
  pickedTileId,
  gameData,
  currentPlayerId,
  gameId,
  currentTurnId,
  rotateTileApi,
  onSuccess,
  onError
}) => {
  if (!pickedTile || !pickedTileId) return false;

  // Check if current orientation is valid
  if (isValidOrientation(position, pickedTile.orientation, gameData, currentPlayerId)) {
    console.log('Initial orientation is valid');
    return true;
  }

  console.log('Initial orientation not valid, trying rotations');

  const requiredOpenSide = getRequiredOpenSide(position, gameData, currentPlayerId);
  if (requiredOpenSide === null) {
    console.error('Could not determine required open side');
    return false;
  }

  // Try each rotation until we find a valid one or complete a full circle
  let currentOrientation = pickedTile.orientation;
  let attempts = 0;

  while (attempts < 4) {
    try {
      const response = await rotateTileApi({
        tileId: pickedTileId,
        topSide: 3,
        requiredOpenSide,
        gameId,
        playerId: currentPlayerId,
        turnId: currentTurnId
      });

      currentOrientation = response.tile.orientation;
      console.log(`Rotation attempt ${attempts + 1}, orientation:`, currentOrientation);

      if (isValidOrientation(position, currentOrientation, gameData, currentPlayerId)) {
        console.log('Found valid orientation after rotation');
        if (onSuccess) {
          onSuccess(response.tile);
        }
        return true;
      }

      attempts++;
    } catch (err) {
      console.error('Failed to rotate tile:', err);
      if (onError) {
        onError(err);
      }
      return false;
    }
  }

  console.log('No valid orientation found after all rotations');
  return false;
};

/**
 * Highlights a tile based on tile data
 * @param {Object} tileData - The tile data containing x, y coordinates or position
 * @param {Array} processedTiles - Array of processed tiles to search through
 * @param {Function} centerViewOnTile - Optional function to center view on the tile
 * @param {boolean} centerView - Whether to center the view on the highlighted tile
 * @returns {Object|null} The found tile object or null if not found
 */
export const highlightTile = (tileData, processedTiles, centerViewOnTile = null, centerView = false) => {
  // Find the tile in processedTiles that matches the coordinates from the event
  const tile = processedTiles.find(t => 
    t.x === tileData.x && t.y === tileData.y || t.position === tileData.position
  );

  if (tile) {
    if (centerView && centerViewOnTile) {
      centerViewOnTile(tileData);
    }
    return tile;
  }
  
  return null;
};

/**
 * Unhighlights a tile (utility function for consistency)
 * @returns {null} Always returns null to clear the highlighted tile
 */
export const unhighlightTile = () => {
  return null;
};
