/**
 * Checks if the specified player is in the game
 * @param {Object} gameData - The game data containing player information
 * @param {string} playerId - The ID of the player to check
 * @returns {boolean} True if the player is in the game, false otherwise
 */
export const isPlayerInGame = (gameData, playerId) => {
  if (!gameData || !gameData.players || !playerId) return false;
  return gameData.players.some(player => player && player.id === playerId) || false;
};

/**
 * Checks if the second player is in the game
 * This function has the same implementation as isPlayerInGame but is kept separate
 * for semantic clarity in the codebase
 * @param {Object} gameData - The game data containing player information
 * @param {string} playerId - The ID of the second player to check
 * @returns {boolean} True if the second player is in the game, false otherwise
 */
export const isSecondPlayerInGame = (gameData, playerId) => {
  if (!gameData || !gameData.players || !playerId) return false;
  return gameData.players.some(player => player && player.id === playerId) || false;
};

/**
 * Checks if it's the specified player's turn
 * @param {Object} gameData - The game data containing state information
 * @param {string} playerId - The ID of the player to check
 * @param {boolean} [enableLogging=false] - Whether to log debug information when it's not the player's turn
 * @returns {boolean} True if it's the player's turn, false otherwise
 */
export const isPlayerTurn = (gameData, playerId, enableLogging = false) => {
  if (!gameData || !gameData.state || !playerId) {
    return false;
  }

  const apiCurrentPlayerId = gameData.state.currentPlayerId;
  const isCurrentTurn = apiCurrentPlayerId === playerId;

  // Log when turn status changes for debugging
  if (enableLogging && !isCurrentTurn && apiCurrentPlayerId && playerId) {
    console.log('Turn check - Not your turn:', { 
      apiPlayerId: apiCurrentPlayerId, 
      uiPlayerId: playerId 
    });
  }

  return isCurrentTurn;
};

/**
 * Checks if the current turn belongs to an AI/virtual player
 * @param {Object} gameData - The game data containing state information
 * @returns {boolean} True if it's the AI player's turn, false otherwise
 */
export const isAITurn = (gameData) => {
  if (!gameData || !gameData.state || !gameData.state.currentPlayerId) {
    return false;
  }
  
  // Check if we have a virtual player ID stored (from game creation)
  const virtualPlayerId = localStorage.getItem('virtualPlayerId');
  if (!virtualPlayerId) {
    return false;
  }
  
  // If current player from API matches the virtual player, it's the AI's turn
  return gameData.state.currentPlayerId === virtualPlayerId;
};

/**
 * Gets an emoji representation for a player based on their player ID
 * @param {string} playerId - The ID of the player
 * @returns {string} An emoji character representing the player
 */
export const getPlayerEmoji = (playerId) => {
  if (!playerId) return '👤';
  
  // Simple check: if this player matches the virtual player ID, show robot emoji
  const virtualPlayerId = typeof localStorage !== 'undefined' ? localStorage.getItem('virtualPlayerId') : null;
  if (virtualPlayerId && playerId === virtualPlayerId) {
    return '🤖';
  }
  
  // Expanded emoji set for more variety and better uniqueness
  const playerEmojis = [
    '👩🏻‍🌾', '💂🏻‍♀️', '👩🏻‍🎓', '👩🏻‍🔧', '🧙‍♂️', // Original set
    '🧝‍♂️', '🧛‍♀️', '🧚‍♂️', '🦸‍♀️', '🦹‍♂️', // Fantasy characters
    '👨‍🚀', '👮‍♀️', '👷‍♂️', '🕵️‍♀️', '👨‍🍳', // Professions
    '🤴', '👸', '🧞‍♂️', '🧟‍♀️', '👨‍⚕️' // More characters
  ];
  
  // Create a more unique hash from the player ID
  // Use more characters from the ID to reduce collision chance
  let hash = 0;
  for (let i = 0; i < Math.min(playerId.length, 8); i++) {
    hash = ((hash << 5) - hash) + playerId.charCodeAt(i);
    hash = hash & hash; // Convert to 32bit integer
  }
  
  // Ensure positive index
  hash = Math.abs(hash);
  
  // Use modulo to get index within emoji array range
  const index = hash % playerEmojis.length;
  
  return playerEmojis[index];
};

/**
 * Generates a UUID v4 compatible string
 * @returns {string} A randomly generated UUID
 */
export const generateUUID = () => {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
};

/**
 * Joins a game with the specified player ID
 * @param {string} gameId - The ID of the game to join
 * @param {string|null} currentPlayerId - The current player ID (or null if a new ID should be generated)
 * @param {Function} gameApi - The game API service containing the joinGame method
 * @param {Function} updateGameDataCallback - Callback to update the game data after joining
 * @returns {Promise<Object>} An object containing the player ID and updated game data
 * @throws {Error} If joining the game fails
 */
export const joinGame = async (gameId, currentPlayerId, gameApi, updateGameDataCallback) => {
  try {
    // Generate a new player ID if one doesn't exist
    if (!currentPlayerId) {
      currentPlayerId = generateUUID();
    }

    console.log('Joining game with ID:', gameId, 'as player:', currentPlayerId);

    // Join the game with username if available
    const username = localStorage.getItem('monadUsername') || null;
    const externalId = localStorage.getItem('privyUserId') || null;
    await gameApi.joinGame(gameId, currentPlayerId, externalId, username);

    // Refresh game data
    const updatedGameData = await gameApi.getGame(gameId);

    // Update game data if callback is provided
    if (typeof updateGameDataCallback === 'function') {
      updateGameDataCallback(updatedGameData);
    }

    console.log('Successfully joined game as player:', currentPlayerId);

    return {
      playerId: currentPlayerId,
      gameData: updatedGameData
    };
  } catch (err) {
    console.error('Failed to join game:', err);
    throw new Error(`Failed to join game: ${err.message}`);
  }
};

/**
 * Marks a player as ready in a game
 * @param {string} gameId - The ID of the game
 * @param {string} playerId - The ID of the player to mark as ready
 * @param {Function} gameApi - The game API service containing the playerReady method
 * @param {Function} updateGameDataCallback - Callback to update the game data after marking player as ready
 * @param {Function} setLoadingCallback - Optional callback to set loading state
 * @param {Function} setLoadingStatusCallback - Optional callback to set loading status message
 * @param {Function} setErrorCallback - Optional callback to set error message
 * @returns {Promise<Object>} An object containing the updated game data
 * @throws {Error} If marking the player as ready fails
 */

/**
 * Formats player IDs to make them more readable
 * @param {string} playerId - The player ID to format
 * @returns {string} The formatted player ID
 */
export const formatPlayerId = (playerId) => {
  if (!playerId) return 'Unknown';

  // If it's a UUID format, show only the first part
  if (playerId.includes('-') && playerId.length > 8) {
    return playerId.split('-')[0];
  }

  // If it's a long string, show first and last few characters
  if (playerId.length > 12) {
    return `${playerId.substring(0, 6)}...${playerId.substring(playerId.length - 4)}`;
  }

  return playerId;
};

/**
 * Switches between the current player and second player
 * @param {Object} params - Parameters for switching players
 * @param {Ref<string>} params.currentPlayerId - Reference to current player ID
 * @param {Ref<string>} params.secondPlayerId - Reference to second player ID
 * @param {Function} params.loadGameData - Function to reload game data
 */
export const switchPlayer = ({ currentPlayerId, secondPlayerId, loadGameData }) => {
  if (!currentPlayerId.value || !secondPlayerId.value) return;

  // Swap the player IDs
  const tempId = currentPlayerId.value;
  currentPlayerId.value = secondPlayerId.value;
  secondPlayerId.value = tempId;

  // Update localStorage
  localStorage.setItem('currentPlayerId', currentPlayerId.value);
  localStorage.setItem('secondPlayerId', secondPlayerId.value);

  // Refresh the game data to reflect the current player's view
  loadGameData();

  console.log('Switched to player:', currentPlayerId.value);
};

/**
 * Automatically switches to the current player based on game state
 * @param {Object} params - Parameters for auto-switching players
 * @param {Object} params.gameData - The game data object
 * @param {Ref<string>} params.currentPlayerId - Reference to current player ID
 * @param {Ref<string>} params.secondPlayerId - Reference to second player ID
 * @param {Function} params.showPlayerSwitchNotification - Function to show player switch notification
 */
export const autoSwitchPlayer = ({ gameData, currentPlayerId, secondPlayerId, showPlayerSwitchNotification }) => {
  if (!gameData || !gameData.state || !gameData.state.currentPlayerId) return;

  const apiCurrentPlayerId = gameData.state.currentPlayerId;
  
  // Check if there's a virtual player in the game
  const virtualPlayerId = localStorage.getItem('virtualPlayerId');
  
  // If the API's current player is the virtual player, don't switch
  // Keep control with the human player
  if (virtualPlayerId && apiCurrentPlayerId === virtualPlayerId) {
    console.log('AI turn detected, keeping human player control');
    // Don't change anything, human stays in control
    return;
  }
  
  console.log('Auto-switching player check:', { 
    apiCurrentPlayerId, 
    currentPlayerId: currentPlayerId.value, 
    secondPlayerId: secondPlayerId.value 
  });

  // If we have both players stored locally (for local multiplayer)
  if (currentPlayerId.value && secondPlayerId.value) {
    // If the API's current player doesn't match our current player,
    // we need to ensure the correct player is active
    if (apiCurrentPlayerId !== currentPlayerId.value) {
      // Check if the API's current player matches our second player
      if (apiCurrentPlayerId === secondPlayerId.value) {
        console.log('Switching from player', currentPlayerId.value, 'to', secondPlayerId.value);
        // Swap the player IDs to make the second player the current one
        const tempId = currentPlayerId.value;
        currentPlayerId.value = secondPlayerId.value;
        secondPlayerId.value = tempId;
        localStorage.setItem('currentPlayerId', currentPlayerId.value);
        localStorage.setItem('secondPlayerId', secondPlayerId.value);

        // Show player switch notification
        showPlayerSwitchNotification();
      } else {
        // If neither current nor second player matches the API's current player,
        // we should update our current player to match the API
        console.log('Setting current player to API current player:', apiCurrentPlayerId);
        secondPlayerId.value = currentPlayerId.value;
        currentPlayerId.value = apiCurrentPlayerId;
        localStorage.setItem('currentPlayerId', currentPlayerId.value);
        localStorage.setItem('secondPlayerId', secondPlayerId.value);

        // Show player switch notification
        showPlayerSwitchNotification();
      }
    }
  } else if (currentPlayerId.value) {
    // We only have one player stored locally
    if (apiCurrentPlayerId !== currentPlayerId.value) {
      // If our stored player doesn't match the API's current player,
      // we need to update it
      console.log('API current player differs from stored player. Updating...');
      secondPlayerId.value = currentPlayerId.value;
      currentPlayerId.value = apiCurrentPlayerId;
      localStorage.setItem('currentPlayerId', apiCurrentPlayerId);
      localStorage.setItem('secondPlayerId', secondPlayerId.value);

      // Show player switch notification
      showPlayerSwitchNotification();
    }
  } else {
    // We don't have any players stored locally
    console.log('No local player, using API current player:', apiCurrentPlayerId);
    currentPlayerId.value = apiCurrentPlayerId;
    localStorage.setItem('currentPlayerId', apiCurrentPlayerId);
  }
};

export const getPlayerReady = async (
  gameId, 
  playerId, 
  gameApi, 
  updateGameDataCallback,
  setLoadingCallback = null,
  setLoadingStatusCallback = null,
  setErrorCallback = null
) => {
  if (!playerId || !gameId) {
    const error = 'Cannot mark player as ready: Missing player ID or game ID';
    console.error(error);
    if (setErrorCallback) setErrorCallback(error);
    throw new Error(error);
  }

  try {
    // Start loading without destroying UI if callbacks provided
    if (setLoadingCallback) setLoadingCallback(true);
    if (setLoadingStatusCallback) setLoadingStatusCallback('Getting player ready...');

    console.log('Marking player as ready:', playerId, 'in game:', gameId);

    // Call the player ready API
    await gameApi.playerReady(gameId, playerId);

    // Refresh game data
    const updatedGameData = await gameApi.getGame(gameId);

    // Update game data if callback is provided
    if (typeof updateGameDataCallback === 'function') {
      updateGameDataCallback(updatedGameData);
    }

    console.log('Successfully marked player as ready');

    return {
      success: true,
      gameData: updatedGameData
    };
  } catch (err) {
    console.error('Failed to mark player as ready:', err);
    const errorMessage = `Failed to mark player as ready: ${err.message}`;
    if (setErrorCallback) setErrorCallback(errorMessage);
    throw new Error(errorMessage);
  } finally {
    if (setLoadingCallback) setLoadingCallback(false);
    if (setLoadingStatusCallback) setLoadingStatusCallback('');
  }
}; 
