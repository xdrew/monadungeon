/**
 * The game's theme: monster/hero names and images, app name, page title.
 * Everything flavour-related is read from here.
 */
import base from './base';

export const theme = base;

/**
 * Finds the monster entry for a backend item name. Matches by prefix/substring like the
 * old monsterUtils did (e.g. "skeleton_king_x" → skeleton_king); an unknown name picks a
 * look by HP via theme.monsterFallbackByHp.
 * @param {string|null|undefined} key
 * @param {number} [hp=0]
 * @returns {{ key: string, name: string, emoji: string, image: string|null }}
 */
export const getMonster = (key, hp = 0) => {
  const name = typeof key === 'string' ? key.toLowerCase() : '';
  if (name) {
    for (const [monsterKey, monster] of Object.entries(theme.monsters)) {
      if (name.includes(monsterKey)) {
        return { key: monsterKey, ...monster };
      }
    }
  }
  const fallback = theme.monsterFallbackByHp.find((entry) => (hp || 0) >= entry.minHp)
    || theme.monsterFallbackByHp[theme.monsterFallbackByHp.length - 1];
  return { key: fallback.key, ...theme.monsters[fallback.key] };
};

export const getMonsterName = (key, hp = 0) => getMonster(key, hp).name;
export const getMonsterImage = (key, hp = 0) => getMonster(key, hp).image;
export const getMonsterEmoji = (key, hp = 0) => getMonster(key, hp).emoji;

/**
 * Hero entry for a HeroType value; null/unknown → theme.heroes.none ("Adventurer").
 * @param {string|null|undefined} key
 * @returns {{ key: string, name: string, emoji: string, image: string|null }}
 */
export const getHero = (key) => {
  const heroKey = key && key in theme.heroes ? key : 'none';
  return { key: heroKey, ...theme.heroes[heroKey] };
};
