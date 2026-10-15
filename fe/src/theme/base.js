/**
 * Base theme — the Karak board game look: Karak monster and hero names, no brand logo,
 * no Monad Games ID sign-in. Image files live in fe/public/themes/base/ (see its README);
 * a missing file falls back to the emoji (ThemedImage.vue).
 *
 * Plain data only — this file is also imported by the Vite config (vite-plugins/themeHtml.js),
 * so it must not touch import.meta.env or the DOM.
 */
const MONSTER_IMAGES = '/themes/base/monsters';
const HERO_IMAGES = '/themes/base/heroes';

export default {
  name: 'base',
  appName: 'Karak',
  pageTitle: 'Karak - Dungeon Crawler',
  manifest: '/themes/base/manifest.json',
  logo: null,
  /** Lobby background art (null = plain dark background) */
  lobbyBackground: '/themes/base/lobby-bg.webp',
  monadGamesId: false,

  /** Keyed by ItemName value from the backend. */
  monsters: {
    giant_rat: { name: 'Giant Rat', emoji: '🐀', image: `${MONSTER_IMAGES}/giant_rat.webp` },
    giant_spider: { name: 'Giant Spider', emoji: '🕷️', image: `${MONSTER_IMAGES}/giant_spider.webp` },
    mummy: { name: 'Mummy', emoji: '🧟', image: `${MONSTER_IMAGES}/mummy.webp` },
    skeleton_turnkey: { name: 'Skeleton Turnkey', emoji: '🔐', image: `${MONSTER_IMAGES}/skeleton_turnkey.webp` },
    skeleton_warrior: { name: 'Skeleton Warrior', emoji: '🛡️', image: `${MONSTER_IMAGES}/skeleton_warrior.webp` },
    skeleton_king: { name: 'Skeleton King', emoji: '👑', image: `${MONSTER_IMAGES}/skeleton_king.webp` },
    fallen: { name: 'Fallen', emoji: '👻', image: `${MONSTER_IMAGES}/fallen.webp` },
    dragon: { name: 'Dragon', emoji: '🐉', image: `${MONSTER_IMAGES}/dragon.webp` },
    // Karak: Regent
    giant_bat: { name: 'Giant Bat', emoji: '🦇', image: `${MONSTER_IMAGES}/giant_bat.webp` },
    skeleton_mage: { name: 'Skeleton Mage', emoji: '🧊', image: `${MONSTER_IMAGES}/skeleton_mage.webp` }
  },

  /** Unknown monster key → pick a look by HP (first matching entry wins). */
  monsterFallbackByHp: [
    { minHp: 10, key: 'dragon' },
    { minHp: 7, key: 'skeleton_warrior' },
    { minHp: 0, key: 'giant_rat' }
  ],

  /** Keyed by HeroType value from the backend; `none` = no hero chosen. */
  heroes: {
    none: { name: 'Adventurer', emoji: '🧑', image: `${HERO_IMAGES}/adventurer.webp` },
    warrior: { name: 'Horan, the Warrior', emoji: '⚔️', image: `${HERO_IMAGES}/warrior.webp` },
    thief: { name: 'Aderyn, the Thief', emoji: '🗡️', image: `${HERO_IMAGES}/thief.webp` },
    swordsman: { name: 'Victorius, the Swordsman', emoji: '🤺', image: `${HERO_IMAGES}/swordsman.webp` },
    warlock: { name: 'Lord Xanros, the Warlock', emoji: '🧙', image: `${HERO_IMAGES}/warlock.webp` },
    wizard: { name: 'Argentus, the Wizard', emoji: '🪄', image: `${HERO_IMAGES}/wizard.webp` },
    oracle: { name: 'Taia, the Oracle', emoji: '👁️', image: `${HERO_IMAGES}/oracle.webp` },
    // Karak: Regent heroes
    barbarian: { name: 'Valduk, the Barbarian', emoji: '🪓', image: `${HERO_IMAGES}/barbarian.webp` },
    battle_mage: { name: 'Baron Markul, the Battle Mage', emoji: '⚡', image: `${HERO_IMAGES}/battle_mage.webp` },
    acrobat: { name: 'Hanna, the Acrobat', emoji: '🤸', image: `${HERO_IMAGES}/acrobat.webp` },
    ranger: { name: 'Lady Lorraine, the Ranger', emoji: '🐻', image: `${HERO_IMAGES}/ranger.webp` },
    // Karak: Regent, Lord Karak variant — the weakest hero transformed
    lord_karak: { name: 'Lord Karak', emoji: '👑', image: `${HERO_IMAGES}/lord_karak.webp` }
  }
};
