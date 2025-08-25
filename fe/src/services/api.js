import axios from 'axios';


/**
 * @typedef {Object} GameResponse
 * @property {string} gameId - Unique identifier for the game
 */

// Function to generate a random UUID-like string temporarily until uuid package is installed
const generateRandomId = () => {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
};

// Create axios instance with base configuration
const apiClient = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  },
  timeout: 10000
});

// Game related API methods
export const gameApi = {
  /**
   * Create a new game
   * @param {string} [gameId] - Optional game ID, will generate a random ID if not provided
   * @returns {Promise<GameResponse>} Promise with game response data
   */
  createGame: async (gameId = null) => {
    const payload = {
      gameId: gameId || generateRandomId()
    };
    
    try {
      const response = await apiClient.post('/game', payload);
      console.log('Game created:', response.data);

      // Example structure of expected response:
      // {
      //   gameId: "f47ac10b-58cc-4372-a567-0e02b2c3d479",
      //   createdAt: "2023-05-20T14:22:15Z",
      //   state: {
      //     status: "waiting",
      //     turn: 0,
      //     board: {}
      //   },
      //   players: [],
      //   settings: {}
      // }

      return response.data;
    } catch (error) {
      console.error('Error creating game:', error);
      throw error;
    }
  },
  
  /**
   * Get game details by ID
   * @param {string} gameId - The game ID to retrieve
   * @returns {Promise<GameResponse>} Promise with game response data
   */
  getGame: async (gameId) => {
    try {
      const response = await apiClient.get(`/game/${gameId}`);
      return response.data;
    } catch (error) {
      console.error(`Error getting game ${gameId}:`, error);
      throw error;
    }
  },

  joinGame: async (gameId, playerId, externalId = null, username = null, walletAddress = null, isAi = false) => {
    try {
      const payload = {gameId: gameId, playerId: playerId, isAi: isAi};
      if (externalId) {
        payload.externalId = externalId;
      }
      if (username) {
        payload.username = username;
      }
      if (walletAddress) {
        payload.walletAddress = walletAddress;
      }
      const response = await apiClient.post(`/game/player`, payload);
      return response.data;
    } catch (error) {
      console.error(`Error joining game ${gameId}:`, error);
      throw error;
    }
  },
  
  /**
   * Mark player as ready in a game
   * @param {string} gameId - The game ID
   * @param {string} playerId - The player ID
   * @returns {Promise<Object>} Promise with response data
   */
  playerReady: async (gameId, playerId) => {
    try {
      const response = await apiClient.post(`/game/player/ready`, {
        gameId: gameId,
        playerId: playerId
      });
      console.log('Player marked as ready:', response.data);
      return response.data;
    } catch (error) {
      console.error(`Error marking player ${playerId} as ready in game ${gameId}:`, error);
      throw error;
    }
  },
  
  /**
   * Start a game
   * @param {string} gameId - The game ID to start
   * @returns {Promise<Object>} Promise with response data
   */
  startGame: async (gameId) => {
    try {
      const response = await apiClient.post(`/game/start`, {
        gameId: gameId
      });
      console.log('Game started:', response.data);
      return response.data;
    } catch (error) {
      console.error(`Error starting game ${gameId}:`, error);
      throw error;
    }
  },

  /**
   * Get all turns for a game
   * @param {string} gameId - The game ID to retrieve turns for
   * @returns {Promise<Object>} Promise with game turns data
   */
  getGameTurns: async (gameId) => {
    try {
      const response = await apiClient.get(`/game/${gameId}/turns`);
      
      // Log response for debugging
      console.log('Game turns fetched:', response.data);
      
      // Basic validation of response structure
      if (!response.data || !response.data.gameId || !Array.isArray(response.data.turns)) {
        console.warn('Unexpected game turns response format:', response.data);
      }
      
      return response.data;
    } catch (error) {
      console.error(`Error fetching game turns for game ${gameId}:`, error);
      throw error;
    }
  },

  /**
   * Pick a tile for placement
   * @param {Object} params - The parameters for picking a tile
   * @param {string} params.gameId - The game ID
   * @param {string} params.tileId - The tile ID to pick
   * @param {string} params.playerId - The player ID
   * @param {string} params.turnId - The current turn ID
   * @param {number} params.requiredOpenSide - The side that must be open after picking/rotating (mandatory)
   * @returns {Promise<Object>} Promise with picked tile data
   * @throws {Error} If requiredOpenSide is not provided
   */
  pickTile: async ({ gameId, tileId, playerId, turnId, requiredOpenSide }) => {
    if (requiredOpenSide === undefined) {
      throw new Error('requiredOpenSide is required when picking a tile');
    }

    try {
      const response = await apiClient.post('/game/pick-tile', {
        gameId,
        tileId,
        playerId,
        turnId,
        requiredOpenSide
      });
      console.log('Tile picked:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error picking tile:', error);
      throw error;
    }
  },

  /**
   * Rotate a picked tile
   * @param {Object} params - The parameters for rotating a tile
   * @param {string} params.tileId - The tile ID to rotate
   * @param {number} params.topSide - The new top side orientation
   * @param {number} params.requiredOpenSide - The side that must remain open after rotation
   * @param {string} params.gameId - The game ID
   * @param {string} params.playerId - The player ID
   * @param {string} params.turnId - The current turn ID
   * @returns {Promise<Object>} Promise with rotated tile data
   */
  rotateTile: async ({ tileId, topSide, requiredOpenSide, gameId, playerId, turnId }) => {
    try {
      const response = await apiClient.post('/game/rotate-tile', {
        tileId,
        topSide,
        requiredOpenSide,
        gameId,
        playerId,
        turnId
      });
      console.log('Tile rotated:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error rotating tile:', error);
      throw error;
    }
  },

  /**
   * Place a tile on the field
   * @param {Object} params - The parameters for placing a tile
   * @param {string} params.tileId - The tile ID to place
   * @param {string} params.gameId - The game ID
   * @param {string} params.fieldPlace - The position to place the tile
   * @param {string} params.playerId - The player ID
   * @param {string} params.turnId - The current turn ID
   * @returns {Promise<Object>} Promise with placed tile data
   */
  placeTile: async ({ tileId, gameId, fieldPlace, playerId, turnId }) => {
    try {
      const response = await apiClient.post('/game/place-tile', {
        tileId,
        gameId,
        fieldPlace,
        playerId,
        turnId
      });
      console.log('Tile placed:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error placing tile:', error);
      throw error;
    }
  },

  /**
   * Move a player on the field
   * @param {Object} params - The parameters for moving a player
   * @param {string} params.gameId - The game ID
   * @param {string} params.playerId - The player ID
   * @param {string} params.turnId - The current turn ID
   * @param {string} params.fromPosition - The starting position
   * @param {string} params.toPosition - The destination position
   * @param {boolean} [params.ignoreMonster=false] - Whether to ignore monster encounters
   * @param {boolean} [params.isTilePlacementMove=false] - Whether this move is part of tile placement (prevents turn ending)
   * @returns {Promise<Object>} Promise with move result data
   */
  movePlayer: async ({ gameId, playerId, turnId, fromPosition, toPosition, ignoreMonster = false, isTilePlacementMove = false }) => {
    try {
      const response = await apiClient.post('/game/move-player', {
        gameId,
        playerId,
        turnId,
        fromPosition,
        toPosition,
        ignoreMonster,
        isTilePlacementMove
      });
      console.log('Player moved:', response.data);
      
      // Check if the response contains battle info
      if (response.data && response.data.battleInfo) {
        return {
          ...response.data,
          battleInfo: response.data.battleInfo
        };
      }
      
      return response.data;
    } catch (error) {
      console.error('Error moving player:', error);
      throw error;
    }
  },
  
  /**
   * Pick up an item from the field
   * @param {Object} data
   * @param {string} data.gameId - The game ID
   * @param {string} data.playerId - The player ID
   * @param {string} data.turnId - The turn ID
   * @param {string} data.position - The position of the item
   * @param {string} [data.itemIdToReplace] - Optional ID of item to replace if inventory is full
   * @returns {Promise<Object>} Promise with picked item data
   */
  pickItem: async function(data) {
    try {
      const payload = {
        gameId: data.gameId,
        playerId: data.playerId,
        turnId: data.turnId,
        position: data.position
      };
      
      // Add itemIdToReplace if provided
      if (data.itemIdToReplace) {
        payload.itemIdToReplace = data.itemIdToReplace;
      }
      
      const response = await apiClient.post('/game/pick-item', payload);
      
      // Map response properties to match frontend expectations if needed
      const result = response.data;
      
      // Normalize property names if the backend uses snake_case
      if (!result.inventoryFull && result.inventory_full) {
        result.inventoryFull = result.inventory_full;
      }
      if (!result.itemCategory && result.item_category) {
        result.itemCategory = result.item_category;
      }
      if (!result.maxItemsInCategory && result.max_items_in_category) {
        result.maxItemsInCategory = result.max_items_in_category;
      }
      if (!result.currentInventory && result.current_inventory) {
        result.currentInventory = result.current_inventory;
      }
      if (!result.missingKey && result.missing_key) {
        result.missingKey = result.missing_key;
      }
      if (!result.chestType && result.chest_type) {
        result.chestType = result.chest_type;
      }
      if (!result.itemReplaced && result.item_replaced) {
        result.itemReplaced = result.item_replaced;
      }
      
      return result;
    } catch (error) {
      console.error('Error picking up item:', error);
      throw error;
    }
  },

  /**
   * Handle inventory actions (replace or skip)
   * @param {Object} params - The parameters for the inventory action
   * @param {string} params.gameId - The game ID
   * @param {string} params.playerId - The player ID
   * @param {string} params.action - The action to take ('replace' or 'skip')
   * @param {Object} params.item - The item that was dropped
   * @param {string} [params.itemIdToReplace] - For 'replace' action, the ID of the item to replace
   * @returns {Promise<Object>} Promise with action result data
   */
  inventoryAction: async ({ gameId, playerId, action, item, itemIdToReplace }) => {
    try {
      const payload = {
        gameId,
        playerId,
        action,
        item
      };
      
      // Add itemIdToReplace only if the action is 'replace'
      if (action === 'replace' && itemIdToReplace) {
        payload.itemIdToReplace = itemIdToReplace;
      }
      
      const response = await apiClient.post('/game/inventory-action', payload);
      console.log('Inventory action processed:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error processing inventory action:', error);
      throw error;
    }
  },
  
  /**
   * End the current turn
   * @param {Object} params - The parameters for ending a turn
   * @param {string} params.gameId - The game ID
   * @param {string} params.playerId - The player ID
   * @param {string} params.turnId - The current turn ID
   * @returns {Promise<Object>} Promise with turn end result data
   */
  endTurn: async ({ gameId, playerId, turnId }) => {
    try {
      const response = await apiClient.post('/game/end-turn', {
        gameId,
        playerId,
        turnId
      });
      console.log('Turn ended:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error ending turn:', error);
      throw error;
    }
  },

  /**
   * Force the game to move to the next turn - special method for stunned players
   * @param {Object} params - The parameters for forcing next turn
   * @param {string} params.gameId - The game ID
   * @param {string} params.playerId - The current player ID 
   * @returns {Promise<Object>} Promise with force next turn result
   */
  forceNextTurn: async ({ gameId, playerId }) => {
    try {
      console.log('Attempting to force next turn for stunned player');
      
      // First, get current game state to get the latest turn ID
      const gameState = await apiClient.get(`/game/${gameId}`);
      const currentTurnId = gameState.data.state.currentTurnId;
      
      if (!currentTurnId) {
        throw new Error('Could not get current turn ID');
      }
      
      // Call end turn with the current turn ID
      const response = await apiClient.post('/game/end-turn', {
        gameId,
        playerId,
        turnId: currentTurnId
      });
      
      console.log('First end turn completed:', response.data);
      
      // Wait a moment for server processing
      await new Promise(resolve => setTimeout(resolve, 500));
      
      // Get updated game state
      const updatedGameState = await apiClient.get(`/game/${gameId}`);
      
      // If turn didn't advance, try one more time
      if (updatedGameState.data.state.currentPlayerId === playerId) {
        console.log('Turn did not advance, trying once more');
        
        // Get the possibly new turn ID
        const newTurnId = updatedGameState.data.state.currentTurnId;
        
        // Call end turn again
        const secondResponse = await apiClient.post('/game/end-turn', {
          gameId,
          playerId,
          turnId: newTurnId
        });
        
        console.log('Second end turn completed:', secondResponse.data);
        
        // Get final game state
        const finalGameState = await apiClient.get(`/game/${gameId}`);
        return finalGameState.data;
      }
      
      return updatedGameState.data;
    } catch (error) {
      console.error('Error forcing next turn:', error);
      throw error;
    }
  },

  /**
   * Finalize battle with selected consumables
   * @param {Object} params - The parameters for finalizing battle
   * @param {string} params.battleId - The battle ID
   * @param {string} params.gameId - The game ID
   * @param {string} params.playerId - The player ID
   * @param {string} params.turnId - The current turn ID
   * @param {Array<string>} params.selectedConsumableIds - Array of consumable item IDs to use
   * @param {boolean} [params.pickupItem=false] - Whether to pick up the item after battle (if won)
   * @param {string} [params.replaceItemId] - ID of the item to replace if inventory is full
   * @returns {Promise<Object>} Promise with finalized battle result data
   */
  finalizeBattle: async ({ battleId, gameId, playerId, turnId, selectedConsumableIds, pickupItem = false, replaceItemId = null }) => {
    try {
      const payload = {
        battleId,
        gameId,
        playerId,
        turnId,
        selectedConsumableIds
      };
      
      // Add optional parameters if provided
      if (pickupItem !== undefined) {
        payload.pickupItem = pickupItem;
      }
      
      if (replaceItemId) {
        payload.replaceItemId = replaceItemId;
      }
      
      const response = await apiClient.post('/game/finalize-battle', payload);
      console.log('Battle finalized:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error finalizing battle:', error);
      throw error;
    }
  },

  /**
   * Get player status including whether they're stunned
   * @param {string} playerId - The player ID to check status for
   * @returns {Promise<Object>} Promise with player status data
   */
  getPlayerStatus: async (playerId) => {
    try {
      const response = await apiClient.post('/game/player/status', {
        playerId
      });
      console.log('Player status fetched:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error fetching player status:', error);
      throw error;
    }
  },

  /**
   * Use a spell from player's inventory
   * @param {string} gameId - The game ID
   * @param {string} playerId - The player ID
   * @param {string} turnId - The current turn ID
   * @param {string} spellId - The spell item ID to use
   * @param {Object|null} targetPosition - The target position for teleport spell (e.g., {x: 0, y: 0})
   * @returns {Promise<Object>} Promise with spell use result
   */
  useSpell: async (gameId, playerId, turnId, spellId, targetPosition = null) => {
    try {
      const response = await apiClient.post('/game/use-spell', {
        gameId,
        playerId,
        turnId,
        spellId,
        targetPosition
      });
      console.log('Spell used:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error using spell:', error);
      throw error;
    }
  },

  /**
   * Execute a virtual player turn
   * @param {string} gameId - The game ID
   * @param {string} playerId - The virtual player ID
   * @returns {Promise<Object>} Promise with virtual player actions
   */
  executeVirtualPlayerTurn: async (gameId, playerId) => {
    try {
      const response = await apiClient.post('/game/virtual-player-turn', {
        gameId,
        playerId
      });
      console.log('Virtual player turn executed:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error executing virtual player turn:', error);
      throw error;
    }
  },

  /**
   * Get leaderboard data
   * @param {Object} params - Query parameters
   * @param {number} [params.page=1] - Page number
   * @param {number} [params.limit=20] - Items per page
   * @param {string} [params.sortBy='victories'] - Sort by 'victories' or 'totalGames'
   * @param {string} [params.sortOrder='DESC'] - Sort order 'ASC' or 'DESC'
   * @param {string} [params.currentPlayerWallet] - Current player's wallet address
   * @returns {Promise<Object>} Promise with leaderboard data
   */
  getLeaderboard: async (params = {}) => {
    try {
      const response = await apiClient.get('/leaderboard', { params });
      console.log('Leaderboard fetched:', response.data);
      return response.data;
    } catch (error) {
      console.error('Error fetching leaderboard:', error);
      throw error;
    }
  },

};

// Export the axios instance for custom usage if needed
export default apiClient;
