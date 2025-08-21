/**
 * Utility functions for handling inventory operations in the game
 */

/**
 * Selects an inventory item
 * @param {Object} item - The inventory item to select
 * @param {Object} params - Additional parameters for handling the selection
 * @param {Function} params.onSpellSelected - Callback when a spell is selected
 */
export const selectInventoryItem = (item, params = {}) => {
  console.log('Selected inventory item:', item);
  
  // Check if this is a teleport spell that can be used outside battle
  if (item.type === 'teleport') {
    if (params.onSpellSelected) {
      params.onSpellSelected(item);
    }
  }
  // For other items, just show item details
};

/**
 * Selects an item to replace when inventory is full
 * @param {Object} params - Parameters for selecting item to replace
 * @param {Ref<Object|null>} params.selectedItemToReplace - Reference to selected item to replace
 * @param {Object} params.item - The item to select for replacement
 */
export const selectItemToReplace = ({ selectedItemToReplace, item }) => {
  selectedItemToReplace.value = item;
  console.log('Selected item to replace:', item);
};

/**
 * Replaces an item in the inventory
 * @param {Object} params - Parameters for replacing an item
 * @param {Ref<Object|null>} params.selectedItemToReplace - Reference to selected item to replace
 * @param {Ref<Object|null>} params.droppedItem - Reference to dropped item
 * @param {Ref<boolean>} params.loading - Reference to loading state
 * @param {Ref<string>} params.loadingStatus - Reference to loading status message
 * @param {Ref<boolean>} params.showInventoryFullDialog - Reference to show inventory full dialog flag
 * @param {Ref<string|null>} params.error - Reference to error message
 * @param {Object} params.gameApi - The game API object
 * @param {string} params.gameId - The game ID
 * @param {string} params.playerId - The player ID
 * @param {string} params.turnId - The current turn ID
 * @param {Function} params.loadGameData - Function to reload game data
 */
export const replaceItem = async ({
  selectedItemToReplace,
  droppedItem,
  loading,
  loadingStatus,
  showInventoryFullDialog,
  error,
  gameApi,
  gameId,
  playerId,
  turnId,
  loadGameData
}) => {
  if (!selectedItemToReplace.value || !droppedItem.value) return;

  try {
    loading.value = true;
    loadingStatus.value = 'Replacing item...';

    // First, pick up the item from the field with the replacement
    if (droppedItem.value.position) {
      console.log('Picking up item from field with replacement');
      const pickupResponse = await gameApi.pickItem({
        gameId,
        playerId,
        turnId,
        position: droppedItem.value.position,
        itemIdToReplace: selectedItemToReplace.value.itemId
      });
      
      console.log('Item picked up and replaced successfully:', pickupResponse);
    } else {
      // Fallback to inventory action if no position (shouldn't happen)
      console.warn('No position for dropped item, using inventory action');
      const response = await gameApi.inventoryAction({
        gameId, 
        playerId,
        action: 'replace',
        itemIdToReplace: selectedItemToReplace.value.itemId,
        item: droppedItem.value
      });
      
      console.log('Item replaced successfully:', response);
    }

    // Close the dialog
    showInventoryFullDialog.value = false;
    selectedItemToReplace.value = null;

    // If we have a turn ID, end the turn after replacing the item
    if (turnId) {
      console.log('Ending turn after item replacement...');
      await gameApi.endTurn({
        gameId,
        playerId,
        turnId
      });
    }

    // Update the game data to reflect the new inventory and turn state
    await loadGameData();

  } catch (err) {
    console.error('Failed to replace item:', err);
    error.value = `Failed to replace item: ${err.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

/**
 * Skips picking up an item
 * @param {Object} params - Parameters for skipping an item
 * @param {Ref<Object|null>} params.droppedItem - Reference to dropped item
 * @param {Ref<boolean>} params.loading - Reference to loading state
 * @param {Ref<string>} params.loadingStatus - Reference to loading status message
 * @param {Ref<boolean>} params.showInventoryFullDialog - Reference to show inventory full dialog flag
 * @param {Ref<string|null>} params.error - Reference to error message
 * @param {Object} params.gameApi - The game API object
 * @param {string} params.gameId - The game ID
 * @param {string} params.playerId - The player ID
 * @param {string} params.turnId - The current turn ID
 * @param {Function} params.loadGameData - Function to reload game data
 * @param {boolean} params.isAfterBattle - Whether this skip is happening after a battle (should end turn)
 */
export const skipItem = async ({
  droppedItem,
  loading,
  loadingStatus,
  showInventoryFullDialog,
  error,
  gameApi,
  gameId,
  playerId,
  turnId,
  loadGameData,
  isAfterBattle = false
}) => {
  if (!droppedItem.value) return;

  try {
    loading.value = true;
    loadingStatus.value = 'Skipping item...';

    // Close the dialog first
    showInventoryFullDialog.value = false;

    // Only end turn if this is after a battle
    // If player just stepped on an item tile, they should be able to continue moving
    if (turnId && isAfterBattle) {
      console.log('Skipping item after battle, ending turn...');
      await gameApi.endTurn({
        gameId,
        playerId,
        turnId
      });
      
      // Reload game data to get the updated state
      if (loadGameData) {
        await loadGameData();
      }
      
      console.log('Item skipped and turn ended successfully');
    } else {
      console.log('Item skipped, player can continue moving');
      
      // Still reload game data to ensure UI is in sync
      if (loadGameData) {
        await loadGameData();
      }
    }

  } catch (err) {
    console.error('Failed to skip item and end turn:', err);
    error.value = `Failed to skip item: ${err.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

/**
 * Handles inventory full response
 * @param {Object} params - Parameters for handling inventory full response
 * @param {Object} params.response - The inventory full response
 * @param {Ref<Object|null>} params.droppedItem - Reference to dropped item
 * @param {Ref<string>} params.itemCategory - Reference to item category
 * @param {Ref<number>} params.maxItemsInCategory - Reference to max items in category
 * @param {Ref<Array>} params.inventoryForCategory - Reference to inventory for category
 * @param {Ref<boolean>} params.showInventoryFullDialog - Reference to show inventory full dialog flag
 * @param {Ref<Object|null>} params.selectedItemToReplace - Reference to selected item to replace
 * @param {Function} params.getCurrentPlayerData - Function to get current player data
 */
export const handleInventoryFullResponse = ({
  response,
  droppedItem,
  itemCategory,
  maxItemsInCategory,
  inventoryForCategory,
  showInventoryFullDialog,
  selectedItemToReplace,
  getCurrentPlayerData
}) => {
  console.log('Received inventory full response:', response);

  // Set up the dialog data
  droppedItem.value = response.droppedItem;
  itemCategory.value = response.itemCategory;
  maxItemsInCategory.value = response.maxItemsInCategory;

  // Get the current inventory for this category
  if (getCurrentPlayerData && getCurrentPlayerData.value && getCurrentPlayerData.value.inventory) {
    inventoryForCategory.value = getCurrentPlayerData.value.inventory[response.itemCategory] || [];
  }

  // Show the dialog
  showInventoryFullDialog.value = true;
  selectedItemToReplace.value = null;
};