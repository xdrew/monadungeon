/**
 * Utility functions for game data management
 */
import { generateUUID } from './playerUtils';

/**
 * Helper function to selectively update game data without replacing the entire object
 * This is critical for performance as it prevents unnecessary DOM rebuilds
 * By only updating what changed, we avoid costly re-renders of the entire game field
 * 
 * @param {Object} gameData - The current game data reactive reference
 * @param {Object} updatedData - New data from API to merge in
 * @returns {void}
 */
export const updateGameDataSelectively = (gameData, updatedData) => {
  if (!gameData || !updatedData) return;

  // Update game state information (turn, currentPlayerId, etc.)
  if (updatedData.state) {
    // Update state properties without replacing the entire state object
    if (gameData.state) {
      if (gameData.state.status !== updatedData.state.status) {
        gameData.state.status = updatedData.state.status;
      }
      if (gameData.state.turn !== updatedData.state.turn) {
        gameData.state.turn = updatedData.state.turn;
      }
      if (gameData.state.currentPlayerId !== updatedData.state.currentPlayerId) {
        gameData.state.currentPlayerId = updatedData.state.currentPlayerId;
      }
      if (gameData.state.currentTurnId !== updatedData.state.currentTurnId) {
        gameData.state.currentTurnId = updatedData.state.currentTurnId;
      }

      // Update available places without replacing the entire object
      if (updatedData.state.availablePlaces) {
        if (!gameData.state.availablePlaces) {
          gameData.state.availablePlaces = updatedData.state.availablePlaces;
        } else {
          gameData.state.availablePlaces.moveTo = updatedData.state.availablePlaces.moveTo;
          gameData.state.availablePlaces.placeTile = updatedData.state.availablePlaces.placeTile;
        }
      }
    } else {
      // If state doesn't exist, create it
      gameData.state = updatedData.state;
    }
  }

  // Update players list if changed
  if (updatedData.players && JSON.stringify(gameData.players) !== JSON.stringify(updatedData.players)) {
    gameData.players = updatedData.players;
  }

  // Update field data selectively
  if (updatedData.field && gameData.field) {
    // Update field size if changed
    if (updatedData.field.size) {
      if (gameData.field.size) {
        // Only update size properties that have changed
        if (gameData.field.size.minX !== updatedData.field.size.minX) {
          gameData.field.size.minX = updatedData.field.size.minX;
        }
        if (gameData.field.size.maxX !== updatedData.field.size.maxX) {
          gameData.field.size.maxX = updatedData.field.size.maxX;
        }
        if (gameData.field.size.minY !== updatedData.field.size.minY) {
          gameData.field.size.minY = updatedData.field.size.minY;
        }
        if (gameData.field.size.maxY !== updatedData.field.size.maxY) {
          gameData.field.size.maxY = updatedData.field.size.maxY;
        }
        if (gameData.field.size.width !== updatedData.field.size.width) {
          gameData.field.size.width = updatedData.field.size.width;
        }
        if (gameData.field.size.height !== updatedData.field.size.height) {
          gameData.field.size.height = updatedData.field.size.height;
        }
      } else {
        // If size doesn't exist, create it
        gameData.field.size = updatedData.field.size;
      }
    }

    // Update player positions
    if (updatedData.field.playerPositions) {
      if (!gameData.field.playerPositions) {
        gameData.field.playerPositions = updatedData.field.playerPositions;
      } else {
        // Update only the positions that have changed
        for (const playerId in updatedData.field.playerPositions) {
          if (gameData.field.playerPositions[playerId] !== updatedData.field.playerPositions[playerId]) {
            gameData.field.playerPositions[playerId] = updatedData.field.playerPositions[playerId];
          }
        }
      }
    }

    // Update tile orientations mapping
    if (updatedData.field.tileOrientations) {
      if (!gameData.field.tileOrientations) {
        gameData.field.tileOrientations = updatedData.field.tileOrientations;
      } else {
        // Update only the orientations that have changed
        for (const position in updatedData.field.tileOrientations) {
          if (gameData.field.tileOrientations[position] !== updatedData.field.tileOrientations[position]) {
            gameData.field.tileOrientations[position] = updatedData.field.tileOrientations[position];
          }
        }
      }
    }

    // Update room field places
    if (updatedData.field.roomFieldPlaces && JSON.stringify(gameData.field.roomFieldPlaces) !== JSON.stringify(updatedData.field.roomFieldPlaces)) {
      gameData.field.roomFieldPlaces = updatedData.field.roomFieldPlaces;
    }

    // Update items
    if (updatedData.field.items) {
      if (!gameData.field.items) {
        gameData.field.items = updatedData.field.items;
      } else {
        // Update item data with new values
        for (const position in updatedData.field.items) {
          gameData.field.items[position] = updatedData.field.items[position];
        }

        // Remove items that no longer exist in updated data
        for (const position in gameData.field.items) {
          if (!updatedData.field.items[position]) {
            delete gameData.field.items[position];
          }
        }
      }
    }

    // Most important part - update tiles without replacing the entire array
    if (updatedData.field.tiles && updatedData.field.tiles.length > 0) {
      if (!gameData.field.tiles || gameData.field.tiles.length === 0) {
        // If we don't have tiles yet, just use the new ones
        gameData.field.tiles = updatedData.field.tiles;
      } else {
        // For existing tiles array, update only what's needed

        // Check for new tiles that don't exist in our current gameData
        updatedData.field.tiles.forEach(newTile => {
          // Find if this tile already exists in our current data
          const existingTileIndex = gameData.field.tiles.findIndex(
            tile => tile.position === newTile.position
          );

          if (existingTileIndex === -1) {
            // This is a new tile, add it to our array
            gameData.field.tiles.push(newTile);
          } else {
            // This tile already exists, update its properties if needed
            const existingTile = gameData.field.tiles[existingTileIndex];

            // Only update if tileId changed
            if (existingTile.tileId !== newTile.tileId) {
              existingTile.tileId = newTile.tileId;
            }

            // Preserve any custom properties we've added (like animation flags)
            // while updating the core properties from the API
            for (const key in newTile) {
              if (key !== 'isCurrentAction' && key !== 'isPlacingAnimation' && 
                  key !== 'animationStartX' && key !== 'animationStartY' &&
                  JSON.stringify(existingTile[key]) !== JSON.stringify(newTile[key])) {
                existingTile[key] = newTile[key];
              }
            }
          }
        });

        // Handle tile removals (if any)
        // Check if any tiles in our current data no longer exist in the updated data
        const updatedPositions = updatedData.field.tiles.map(tile => tile.position);
        for (let i = gameData.field.tiles.length - 1; i >= 0; i--) {
          const currentTile = gameData.field.tiles[i];
          if (!updatedPositions.includes(currentTile.position)) {
            // This tile no longer exists in the updated data, remove it
            gameData.field.tiles.splice(i, 1);
          }
        }
      }
    }
  }
};

/**
 * Initialize the game board and debug information
 * 
 * @param {String} gameId - The current game ID
 * @param {Object} gameData - The current game data
 * @param {Function} centerViewOnCurrentPlayer - Function to center view on the current player
 * @returns {void}
 */
export const initGame = (gameId, gameData, centerViewOnCurrentPlayer) => {
  // Log game initialization
  console.log('Game initialized with ID:', gameId);

  // Debug tile orientations if available
  if (gameData?.field?.tileOrientations) {
    console.log('Tile orientations data:', gameData.field.tileOrientations);
  } else {
    console.log('No tile orientations data available');
  }

  // Debug room field places if available
  if (gameData?.field?.roomFieldPlaces) {
    console.log('Room field places:', gameData.field.roomFieldPlaces);
  }

  // Debug items if available
  if (gameData?.field?.items) {
    console.log('Items data:', gameData.field.items);
  } else {
    console.log('No items data available');
  }

  // Center the view on the current player's position after a small delay to ensure DOM is ready
  setTimeout(() => {
    centerViewOnCurrentPlayer();
  }, 100);
}; 

/**
 * Function to join the game with loading and error handling
 * 
 * @param {String} gameId - The ID of the game to join
 * @param {String|null} playerId - The current player ID (or null if a new ID should be generated)
 * @param {Object} gameApi - The game API service
 * @param {Function} updateGameDataSelectively - Function to update game data
 * @param {Function} setLoading - Function to set loading state
 * @returns {Promise<Object>} An object containing the player ID if successful
 */
export const joinGame = async (
  gameId, 
  playerId, 
  gameApi, 
  updateGameDataSelectively,
  setLoading = null
) => {
  try {
    if (setLoading) setLoading(true);

    try {
      // Generate a new player ID if one doesn't exist
      if (!playerId) {
        playerId = generateUUID();
      }

      console.log('Joining game with ID:', gameId, 'as player:', playerId);

      // Join the game with username if available
      const username = localStorage.getItem('monadUsername') || null;
      const externalId = localStorage.getItem('privyUserId') || null;
      await gameApi.joinGame(gameId, playerId, externalId, username);

      // Refresh game data
      const updatedGameData = await gameApi.getGame(gameId);

      // Update game data if callback is provided
      if (typeof updateGameDataSelectively === 'function') {
        updateGameDataSelectively(updatedGameData);
      }

      console.log('Successfully joined game as player:', playerId);

      return {
        playerId: playerId,
        gameData: updatedGameData
      };
    } catch (err) {
      console.error('Failed to join game:', err);
      throw new Error(err.message || 'Failed to join game');
    }
  } finally {
    if (setLoading) setLoading(false);
  }
};

/**
 * Function to start the game with loading and error handling
 * 
 * @param {String} gameId - The ID of the game to start
 * @param {Object} gameApi - The game API service
 * @param {Function} updateGameDataSelectively - Function to update game data
 * @param {Function} setLoading - Function to set loading state
 * @param {Function} setLoadingStatus - Function to set loading status
 * @param {Function} setError - Function to set error state
 * @param {Function} setGameStarted - Function to set game started state
 * @returns {Promise<Object>} An object containing the updated game data if successful
 */
export const startGame = async (
  gameId,
  gameApi,
  updateGameDataSelectively,
  setLoading,
  setLoadingStatus,
  setError,
  setGameStarted
) => {
  if (!gameId) {
    console.error('Missing game ID');
    if (setError) setError('Cannot start game: Missing game ID');
    return { success: false, error: 'Missing game ID' };
  }

  try {
    // Start loading without destroying UI
    if (setLoading) setLoading(true);
    if (setLoadingStatus) setLoadingStatus('Starting game...');
    console.log('Starting game:', gameId);

    // Call the start game API
    await gameApi.startGame(gameId);

    // Mark game as started and refresh game data
    if (setGameStarted) setGameStarted(true);
    if (setLoadingStatus) setLoadingStatus('Loading game data...');
    const updatedGameData = await gameApi.getGame(gameId);
    
    // Update game data if callback is provided
    if (typeof updateGameDataSelectively === 'function') {
      updateGameDataSelectively(updatedGameData);
    }

    console.log('Game started successfully');
    return { success: true, gameData: updatedGameData };
  } catch (err) {
    console.error('Failed to start game:', err);
    if (setError) setError(`Failed to start game: ${err.message}`);
    return { success: false, error: err.message };
  } finally {
    if (setLoading) setLoading(false);
    if (setLoadingStatus) setLoadingStatus('');
  }
};
