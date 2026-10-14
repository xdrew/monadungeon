/**
 * Utility functions for monster-related operations in the game.
 * Names, emoji and images come from the active theme (src/theme) — this module only keeps
 * the battle-object based signatures that the components use.
 */
import {
  getMonsterEmoji as themeMonsterEmoji,
  getMonsterImage as themeMonsterImage,
  getMonsterName
} from '@/theme';

/**
 * Gets monster emoji based on battle info
 * @param {Object} battle - The battle information ({ monster_name, monster: hp })
 * @returns {string} The emoji representing the monster
 */
export const getMonsterEmoji = (battle) => {
  if (!battle) return '❓';
  return themeMonsterEmoji(battle.monster_name || '', battle.monster || 0);
};

/**
 * Gets the display name for a monster type in the active theme
 * @param {string} monsterType - The monster type key (e.g. 'giant_spider')
 * @returns {string} The themed monster name
 */
export const getMonsterDisplayName = (monsterType) => {
  if (!monsterType) return 'Monster';
  return getMonsterName(monsterType);
};

/**
 * Gets monster image path based on battle info
 * @param {Object} battle - The battle information ({ monster_name, monster: hp })
 * @returns {string|null} The path to the monster image, or null if the theme has none
 */
export const getMonsterImage = (battle) => {
  if (!battle) return null;
  return themeMonsterImage(battle.monster_name || '', battle.monster || 0);
};
