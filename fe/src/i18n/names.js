/**
 * Localized names of monsters, heroes, items and skills. Keys are the backend values
 * (ItemName, HeroType, item type, HeroType::skills()[].key); forms follow the glossary:
 * monsters name/nom/acc/ins, items nom/acc, heroes name/short/role.
 */
import { t, te } from './index';
import { getMonster } from '@/theme';

/** Skill keys per hero, as HeroType::skills() returns them (the lobby shows them before a game exists). */
export const HERO_SKILL_KEYS = {
  warrior: ['double_attack', 'reincarnation'],
  thief: ['backstab', 'stealth'],
  swordsman: ['combat_training', 'unstoppable'],
  warlock: ['sacrifice', 'magic_swap'],
  wizard: ['magical_affinity', 'astral_walking'],
  oracle: ['farseeing', 'fateweaver'],
  barbarian: ['berserk', 'tireless'],
  battle_mage: ['sword_master', 'lightning_attack'],
  acrobat: ['dagger_throwing', 'sprint'],
  ranger: ['bear_attack', 'listening']
};

/**
 * @param {string|null|undefined} key backend monster name ("skeleton_king_x" works too)
 * @param {'name'|'nom'|'acc'|'ins'} [form='name'] name = capitalised title, nom/acc/ins = in a sentence
 * @param {number} [hp=0] picks a look for an unknown key, as the theme does
 */
export const monsterName = (key, form = 'name', hp = 0) => {
  const resolved = getMonster(key, hp).key;
  return t(`monsters.${resolved}.${form}`);
};

/**
 * @param {object|string|null} heroOrPlayer a HeroType key, or a player ({ hero, lordKarak })
 * @param {'name'|'short'|'role'} [part='name']
 */
export const heroName = (heroOrPlayer, part = 'name') => {
  let key = heroOrPlayer;
  if (heroOrPlayer && typeof heroOrPlayer === 'object') {
    key = heroOrPlayer.lordKarak ? 'lord_karak' : heroOrPlayer.hero;
  }
  if (!key || !te(`heroes.${key}.name`)) key = 'none';
  return t(`heroes.${key}.${part}`);
};

const ITEM_ALIASES = { treasure_chest: 'chest', ruby: 'ruby_chest', fireball_scroll: 'fireball' };

/**
 * @param {string|object|null} typeOrItem item type ("dagger") or an item ({ type })
 * @param {'nom'|'acc'} [form='nom']
 */
export const itemName = (typeOrItem, form = 'nom') => {
  const raw = typeof typeOrItem === 'object' && typeOrItem ? typeOrItem.type || typeOrItem.name : typeOrItem;
  const key = ITEM_ALIASES[raw] || raw;
  if (key && te(`items.${key}.nom`)) return t(`items.${key}.${form}`);
  return t(`items.unknown.${form}`);
};

/**
 * A hero skill's name and description: by its backend key, else the server's English text.
 * @param {{ key?: string, name?: string, description?: string }} skill
 */
export const skillText = (skill) => {
  if (skill?.key && te(`skills.${skill.key}.name`)) {
    return { name: t(`skills.${skill.key}.name`), description: t(`skills.${skill.key}.description`) };
  }
  return { name: skill?.name || '', description: skill?.description || '' };
};
