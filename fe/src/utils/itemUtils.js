import { t, te } from '@/i18n';
import { itemName, monsterName } from '@/i18n/names';
/**
 * Every spell item type (inventory category "spells", max 3); Karak: Regent adds Frost Fist and Dark Thorn.
 * Use this list instead of spelling out spell types, so a new spell is never treated as a treasure.
 */
export const SPELL_TYPES = ['fireball', 'teleport', 'frost_fist', 'dark_thorn'];

/** Every weapon item type (inventory category "weapons", max 2). */
export const WEAPON_TYPES = ['dagger', 'sword', 'axe'];

/** Slot limits per inventory category (treasures are unlimited), matching the backend Player. */
export const INVENTORY_LIMITS = { keys: 1, weapons: 2, spells: 3 };

/**
 * Inventory category of an item type: 'keys' | 'weapons' | 'spells' | 'treasures'
 * @param {string} type
 * @returns {string}
 */
export const getInventoryCategory = (type) => {
  if (type === 'key') return 'keys';
  if (WEAPON_TYPES.includes(type)) return 'weapons';
  if (SPELL_TYPES.includes(type)) return 'spells';
  return 'treasures';
};

/**
 * Whether one more item of this type fits the player's inventory, hero skills included
 * (Hanna "Dagger Throwing": a dagger also fits a free spell slot; her heroFlags are empty while cursed).
 * @param {Object} player - player data from the game state ({ inventory, heroFlags })
 * @param {string} type - item type
 * @returns {boolean}
 */
export const hasRoomForItem = (player, type) => {
  const inventory = player?.inventory;
  if (!type || !inventory) return true;

  const category = getInventoryCategory(type);
  const limit = INVENTORY_LIMITS[category];
  if (limit === undefined) return true;

  if (type === 'dagger' && player?.heroFlags?.throwsDaggers === true
    && (inventory.spells?.length || 0) < INVENTORY_LIMITS.spells) {
    return true;
  }

  return (inventory[category]?.length || 0) < limit;
};

/**
 * Whether a found item is worth offering: it fits, or a full pack holds something it would improve on.
 * Not worth it when every item it could replace is the same or stronger (a second key, a dagger against
 * two swords, a fireball against fireballs). Spells that do different things (a teleport against fireballs)
 * do not compare, so they are offered.
 * @param {Object} player - player data from the game state ({ inventory, heroFlags })
 * @param {Object} item - the item lying on the tile
 * @returns {boolean}
 */
export const isWorthOffering = (player, item) => {
  if (!item?.type || hasRoomForItem(player, item.type)) return true;
  const held = player?.inventory?.[getInventoryCategory(item.type)] || [];
  const flags = player?.heroFlags || {};
  const strength = getItemStrength(item, flags);
  return held.some((own) => own.type !== item.type
    && (strength === 0 || getItemStrength(own, flags) === 0 || getItemStrength(own, flags) < strength));
};

/**
 * Strength of an item in this hero's hands (Baron Markul "Sword Master": a sword has strength 3).
 * @param {Object} item
 * @param {Object} [heroFlags] - the holder's hero skill flags
 * @returns {number}
 */
export const getItemStrength = (item, heroFlags = {}) => {
  if (item?.type === 'sword' && heroFlags?.masterOfSword === true) return 3;
  return getItemDamage(item);
};

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
      case 'frost_fist':
        return '🧊';
      case 'dark_thorn':
        return '🌑';
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
      case 'giant_bat': // Karak: Regent
        return '🦇';
      case 'skeleton_mage': // Karak: Regent
        return '🧊';
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
 * Display name of an item type or a monster key in the current language
 * ("dagger" → Dagger / Кинжал / Sztylet, "skeleton_king" → Skeleton King / Король-скелет);
 * an unknown key falls back to Title Case.
 * @param {string} name - item type or monster name in snake_case
 * @returns {string}
 */
export const formatItemName = (name) => {
  if (!name) return t('common.unknown');
  if (te(`items.${name}.nom`) || ['treasure_chest', 'ruby'].includes(name)) return itemName(name);
  if (te(`monsters.${name}.name`)) return monsterName(name);
  return name.split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

/**
 * @param {string} type - The item type string
 * @returns {string} The item type's display name in the current language
 */
export const formatItemType = (type) => formatItemName(type);

/**
 * Gets detailed tooltip text for an item
 * @param {Object} item - The item object containing name, type, and other properties
 * @returns {string} Formatted tooltip text with item details
 */
export const getItemTooltip = (item) => {
  if (!item) return t('items.tooltip.unknown');

  // For inventory items the type names the item (not the monster that dropped it)
  const lines = [formatItemName(item.type || item.name)];

  // A guard on a field tile
  if (item.guardHP && item.guardHP > 0 && !item.guardDefeated) {
    lines.push(t('items.tooltip.guard', { name: formatItemName(item.name) }));
    lines.push(t('items.tooltip.hp', { hp: item.guardHP }));
  }

  // Damage of weapons and attack spells
  if (['dagger', 'sword', 'axe', 'fireball', 'frost_fist'].includes(item.type)) {
    lines.push(t('items.tooltip.damage', { damage: getItemDamage(item) }));
  }

  if (item.treasureValue && item.treasureValue > 0) {
    lines.push(t('items.tooltip.value', { value: item.treasureValue }));
  }

  return lines.join('\n');
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
    error.value = t('errors.pickUpReason', { reason: err.message });
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
    case 'frost_fist':
      return '🧊';
    case 'dark_thorn':
      return '🌑';
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
    'frost_fist': 2,
    'dark_thorn': 0,
    'teleport': 0,
    'key': 0,
    'chest': 0,
    'ruby_chest': 0
  };
  
  return damageMap[item.type] || 0;
};

/**
 * GameIcon name for an item that has no picture (the field never shows emoji)
 * @param {Object} item - The item object containing type property
 * @returns {string} A GameIcon name
 */
export const getItemIconName = (item) => {
  switch (item?.type) {
    case 'key':
      return 'key';
    case 'dagger':
    case 'sword':
    case 'axe':
      return 'sword';
    case 'fireball':
    case 'teleport':
    case 'frost_fist':
    case 'dark_thorn':
      return 'spells';
    default:
      return 'treasure';
  }
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
      return '/images/key.webp';
    case 'dagger':
      return '/images/dagger.webp';
    case 'sword':
      return '/images/sword.webp';
    case 'axe':
      return '/images/axe.webp';
    case 'fireball':
      return '/images/fireball.webp';
    case 'teleport':
      return '/images/hf-teleport.webp';
    case 'frost_fist':
      return '/images/frost-fist.webp';
    case 'dark_thorn':
      return '/images/dark-thorn.webp';
    case 'chest':
      return '/images/chest-opened.webp';
    case 'ruby_chest':
      return '/images/ruby-chest.webp';
    default:
      return null;
  }
}; 