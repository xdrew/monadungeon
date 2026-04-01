/**
 * Utility functions for monster-related operations in the game
 */

/**
 * Gets monster emoji based on battle info
 * @param {Object} battle - The battle information
 * @returns {string} The emoji representing the monster
 */
export const getMonsterEmoji = (battle) => {
  if (!battle) return '❓';

  // Try to extract monster name from additional data if available
  const monsterName = battle.monster_name || '';

  // Special handling for skeletons
  if (monsterName.includes('skeleton_king')) {
    return '👑'; // Crown for Skeleton King
  } else if (monsterName.includes('skeleton_warrior')) {
    return '🛡️'; // Shield for Skeleton Warrior
  } else if (monsterName.includes('skeleton_turnkey')) {
    return '🔐'; // Lock for Skeleton with Key
  } else if (monsterName.includes('skeleton') && battle.monster_type === 'fireball') {
    return '🔮'; // Crystal ball for Skeleton Mage
  }

  // For other monsters
  switch (monsterName) {
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
    default:
      // Default monsters based on HP value
      if (battle.monster >= 10) {
        return '👹'; // Big monster
      } else if (battle.monster >= 7) {
        return '👺'; // Medium monster
      } else {
        return '😈'; // Small monster
      }
  }
};

/**
 * Gets the Monad display name for a monster type
 * @param {string} monsterType - The monster type key (e.g. 'giant_spider')
 * @returns {string} The Monad character name
 */
export const getMonsterDisplayName = (monsterType) => {
  const nameMap = {
    'skeleton_king': 'Molandak',
    'skeleton_warrior': 'Taekwonnad',
    'skeleton_turnkey': 'Bearded Nad',
    'dragon': 'Bullish',
    'fallen': 'Bee',
    'giant_rat': 'Ikan',
    'giant_spider': 'Moyaki',
    'mummy': 'Ubur'
  };

  for (const [key, name] of Object.entries(nameMap)) {
    if (monsterType && monsterType.includes(key)) {
      return name;
    }
  }

  return monsterType
    ? monsterType.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')
    : 'Monster';
};

/**
 * Gets monster image path based on battle info
 * @param {Object} battle - The battle information
 * @returns {string|null} The path to the monster image, or null if no image available
 */
export const getMonsterImage = (battle) => {
  if (!battle) return null;

  const monsterName = battle.monster_name || '';
  
  // Map monster names to Monad images
  const monsterImageMap = {
    'skeleton_king': '/images/items/molandak.webp',
    'skeleton_warrior': '/images/items/taekwonnad.webp',
    'skeleton_turnkey': '/images/items/bearded.webp',
    'dragon': '/images/items/bullish.webp',
    'fallen': '/images/items/bee.webp',
    'giant_rat': '/images/items/ikan.webp',
    'giant_spider': '/images/items/moyaki.webp',
    'mummy': '/images/items/ubur.webp'
  };

  // Check for exact matches first
  for (const [key, imagePath] of Object.entries(monsterImageMap)) {
    if (monsterName.includes(key)) {
      return imagePath;
    }
  }

  // Default images based on HP value
  if (battle.monster >= 10) {
    return '/images/items/bullish.webp'; // Big monster
  } else if (battle.monster >= 7) {
    return '/images/items/taekwonnad.webp'; // Medium monster
  } else {
    return '/images/items/ikan.webp'; // Small monster
  }
};