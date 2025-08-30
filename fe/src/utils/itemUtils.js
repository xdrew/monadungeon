/**
 * Gets the emoji representation of an item based on its type and name
 * @param {Object} item - The item object containing name, type, and other properties
 * @returns {string} The emoji representing the item
 */
export const getItemEmoji = (item) => {
  if (!item) return '❓';
  
  // Check if guard is defeated or HP is 0 - show reward
  const guardDefeated = item.guardDefeated || item.guardHP === 0;
  
  // If guard is defeated or there's no guard (HP = 0), show the reward
  if (guardDefeated) {
    // Show reward based on item type
    switch (item.type) {
      case 'key':
        return '🔑';
      case 'chest':
        return '📦';
      case 'ruby_chest':
        return '💎';
      case 'dagger':
        return '🗡️';
      case 'sword':
        return '⚔️';
      case 'axe':
        return '🪓';
      case 'fireball':
        return '🔥';
      case 'teleport':
        return '✨';
      default:
        return '💰'; // Default treasure
    }
  } else {
    // Guard is not defeated, show monster
    // Special handling for skeletons based on both name and type
    if (item.name === 'skeleton_king') {
      return '👑'; // Crown for Skeleton King
    } else if (item.name === 'skeleton_warrior') {
      return '🛡️'; // Shield for Skeleton Warrior
    } else if (item.name === 'skeleton_turnkey') {
      return '🔐'; // Lock for Skeleton with Key
    } else if (item.type === 'fireball' && item.name.includes('skeleton')) {
      return '🔮'; // Crystal ball for Skeleton Mage instead of wizard
    }
    
    // For other monsters not specifically handled above
    switch (item.name) {
      case 'dragon':
        return '🐉';
      case 'fallen':
        return '👻';
      case 'giant_rat':
        return '🐀';
      case 'giant_spider':
        return '🕷️';
      case 'mummy':
        return '🧟';
      case 'treasure_chest': // Special case - no guard but might appear as monster
        return '📦';
      case 'random':
        return '❓';
      default:
        // If we couldn't determine the monster type, use a default based on item type
        if (item.type === 'fireball') {
          return '🔮'; // Crystal ball for other mages too
        } else if (item.type === 'axe' || item.type === 'sword' || item.type === 'dagger') {
          return '⚔️'; // Warrior
        }
        return '👹'; // Default monster
    }
  }
};

/**
 * Helper function to format item names for display
 * @param {string} name - The item name in snake_case format
 * @returns {string} The formatted item name in Title Case
 */
export const formatItemName = (name) => {
  if (!name) return 'Unknown';
  
  // Convert snake_case to Title Case
  return name.split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

/**
 * Helper function to format item types for display
 * @param {string} type - The item type string
 * @returns {string} The formatted item type
 */
export const formatItemType = (type) => {
  if (!type) return 'Unknown';
  
  // Special case handling
  switch (type) {
    case 'ruby_chest':
      return 'Ruby Chest';
    default:
      // Convert snake_case to Title Case for other types
      return type.charAt(0).toUpperCase() + type.slice(1);
  }
};

/**
 * Gets detailed tooltip text for an item
 * @param {Object} item - The item object containing name, type, and other properties
 * @returns {string} Formatted tooltip text with item details
 */
export const getItemTooltip = (item) => {
  if (!item) return 'Unknown item';
  
  let tooltip = '';
  
  // For inventory items, only show the item type (not the monster name)
  if (item.type) {
    tooltip += formatItemType(item.type);
  } else {
    // Fallback to name if type is not available
    tooltip += formatItemName(item.name);
  }
  
  // Add guard info if it's a guard (for field items, not inventory)
  if (item.guardHP && item.guardHP > 0 && !item.guardDefeated) {
    tooltip += `\nGuard: ${formatItemName(item.name)}`;
    tooltip += `\nHP: ${item.guardHP}`;
  }
  
  // Add damage info for weapons and spells
  if (['dagger', 'sword', 'axe', 'fireball'].includes(item.type)) {
    tooltip += `\nDamage: +${getItemDamage(item)}`;
  }
  
  // Add treasure value if it's a treasure
  if (item.treasureValue && item.treasureValue > 0) {
    tooltip += `\nValue: ${item.treasureValue}`;
  }
  
  return tooltip;
};

/**
 * Handles clicking on an item to pick it up
 * @param {Object} params - The parameters object
 * @param {Object} params.itemData - The item data that was clicked
 * @param {Object} params.isPlayerTurn - Ref indicating if it's the player's turn
 * @param {Object} params.gameData - Game data ref
 * @param {Object} params.currentPlayerId - Current player ID ref
 * @param {Object} params.loading - Loading state ref
 * @param {Object} params.loadingStatus - Loading status ref
 * @param {Object} params.error - Error state ref
 * @param {Object} params.gameApi - Game API service
 * @param {string} params.gameId - Game ID
 * @param {Object} params.showInventoryFullDialog - Inventory full dialog visibility ref
 * @param {Object} params.droppedItem - Dropped item ref
 * @param {Object} params.itemCategory - Item category ref
 * @param {Object} params.maxItemsInCategory - Max items in category ref
 * @param {Object} params.inventoryForCategory - Inventory for category ref
 * @param {Function} params.loadGameData - Function to reload game data
 */
export const handleItemClick = async ({
  itemData,
  isPlayerTurn,
  gameData,
  currentPlayerId,
  loading,
  loadingStatus,
  error,
  gameApi,
  gameId,
  showInventoryFullDialog,
  droppedItem,
  itemCategory,
  maxItemsInCategory,
  inventoryForCategory,
  loadGameData
}) => {
  console.log('Item clicked:', itemData);

  // Check if it's the player's turn
  if (!isPlayerTurn.value) {
    console.log('Not your turn, cannot pick up items');
    return;
  }

  // Check if player is on the same position as the item
  const playerPosition = gameData.value?.field?.playerPositions?.[currentPlayerId.value];
  if (playerPosition !== itemData.position) {
    console.log('Player is not on the same position as the item');
    return;
  }

  // Check if the item has an undefeated guard
  if (itemData.item && !itemData.item.guardDefeated && itemData.item.guardHP > 0) {
    console.log('Item has an undefeated guard, defeat the guard first');
    return;
  }

  try {
    // Start loading
    loading.value = true;
    loadingStatus.value = 'Picking up item...';

    // Get current turn ID
    const currentTurnId = gameData.value?.state?.currentTurnId;
    if (!currentTurnId) {
      console.error('No current turn ID found');
      return;
    }

    // Call API to pick up the item
    const response = await gameApi.pickItem({
      gameId,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      position: itemData.position
    });

    console.log('Item pick response:', response);

    // Check if player is missing a key for a chest
    if (response.missingKey) {
      console.log('Player missing key for chest:', response.chestType);
      // You'll need to pass the missing key dialog state from GameView
      // This will need to be handled in the calling component (GameView)
      throw new Error(`MISSING_KEY:${response.chestType}`);
    }

    // Check if inventory is full
    if (response.inventoryFull) {
      // Special case for keys: all keys are the same, so auto-replace without asking
      if (response.itemCategory === 'key' || response.itemCategory === 'keys') {
        console.log('Auto-replacing key since all keys are functionally the same');
        
        // Get the first key from current inventory to replace
        const currentKeys = response.currentInventory || [];
        if (currentKeys.length > 0) {
          const keyToReplace = currentKeys[0].itemId;
          
          // Automatically replace the existing key
          const replaceResponse = await gameApi.pickItem({
            gameId,
            playerId: currentPlayerId.value,
            turnId: currentTurnId,
            position: itemData.position,
            itemIdToReplace: keyToReplace
          });
          
          console.log('Key auto-replaced successfully:', replaceResponse);
        } else {
          // Fallback: show normal inventory full dialog if no keys found
          showInventoryFullDialog.value = true;
          droppedItem.value = response.item;
          itemCategory.value = response.itemCategory;
          maxItemsInCategory.value = response.maxItemsInCategory;
          inventoryForCategory.value = response.currentInventory || [];
        }
      } else {
        // Show inventory full dialog for non-key items
        showInventoryFullDialog.value = true;
        droppedItem.value = response.item;
        itemCategory.value = response.itemCategory;
        maxItemsInCategory.value = response.maxItemsInCategory;
        inventoryForCategory.value = response.currentInventory || [];
      }
    }

    // Refresh game data regardless
    await loadGameData();

  } catch (err) {
    console.error('Failed to pick up item:', err);
    error.value = `Failed to pick up item: ${err.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

/**
 * Gets the emoji for inventory items (always shows the item type, not the monster)
 * @param {Object} item - The item object containing type property
 * @returns {string} The emoji representing the item type
 */
export const getInventoryItemEmoji = (item) => {
  if (!item) return '❓';
  
  // For inventory items, always show based on type
  switch (item.type) {
    case 'key':
      return '🔑';
    case 'chest':
      return '📦';
    case 'ruby_chest':
      return '💎';
    case 'dagger':
      return '🗡️';
    case 'sword':
      return '⚔️';
    case 'axe':
      return '🪓';
    case 'fireball':
      return '🔥';
    case 'teleport':
      return '✨';
    default:
      return '💰'; // Default treasure
  }
};

/**
 * Gets the damage bonus for an item based on its type
 * @param {Object} item - The item object containing type property
 * @returns {number} The damage bonus the item provides
 */
export const getItemDamage = (item) => {
  if (!item || !item.type) return 0;
  
  // Map item types to their damage values (matching backend ItemType::getDamage())
  const damageMap = {
    'dagger': 1,
    'sword': 2,
    'axe': 3,
    'fireball': 1,
    'teleport': 0,
    'key': 0,
    'chest': 0,
    'ruby_chest': 0
  };
  
  return damageMap[item.type] || 0;
};

/**
 * Gets the image path for an item based on its type
 * @param {Object} item - The item object containing type property
 * @returns {string|null} The image path for the item or null if no image available
 */
export const getItemImage = (item) => {
  if (!item || !item.type) return null;
  
  switch (item.type) {
    case 'key':
      return '/images/key.png';
    case 'dagger':
      return '/images/dagger.png';
    case 'sword':
      return '/images/sword.png';
    case 'axe':
      return '/images/axe.png';
    case 'fireball':
      return '/images/fireball.png';
    case 'teleport':
      return '/images/hf-teleport.png';
    case 'chest':
      return '/images/chest-opened.png';
    case 'ruby_chest':
      return '/images/ruby-chest.png';
    default:
      return null;
  }
}; 