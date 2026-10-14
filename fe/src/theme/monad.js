/**
 * Monad theme — the Monad Testnet flavour: Monad mascots as monsters, the Monad logo,
 * the purple palette (styles/themes/monad.css, scoped under html[data-theme="monad"])
 * and the Monad Games ID sign-in in the lobby. Enabled with VITE_THEME=monad at build time.
 *
 * Plain data only — this file is also imported by the Vite config (vite-plugins/themeHtml.js),
 * so it must not touch import.meta.env or the DOM.
 */
import base from './base';

const MONSTER_IMAGES = '/themes/monad/monsters';

const heroesWithoutImages = Object.fromEntries(
  Object.entries(base.heroes).map(([key, hero]) => [key, { ...hero, image: null }])
);

export default {
  name: 'monad',
  appName: 'Monadungeon',
  pageTitle: 'Monadungeon - Powered by Monad Testnet',
  manifest: '/themes/monad/manifest.json',
  logo: '/assets/monad-logo-black.webp',
  lobbyBackground: '/themes/monad/lobby-bg.webp',
  monadGamesId: true,

  monsters: {
    giant_rat: { name: 'Ikan', emoji: '🐀', image: `${MONSTER_IMAGES}/giant_rat.webp` },
    giant_spider: { name: 'Moyaki', emoji: '🕷️', image: `${MONSTER_IMAGES}/giant_spider.webp` },
    mummy: { name: 'Ubur', emoji: '🧟', image: `${MONSTER_IMAGES}/mummy.webp` },
    skeleton_turnkey: { name: 'Bearded Nad', emoji: '🔐', image: `${MONSTER_IMAGES}/skeleton_turnkey.webp` },
    skeleton_warrior: { name: 'Taekwonnad', emoji: '🛡️', image: `${MONSTER_IMAGES}/skeleton_warrior.webp` },
    skeleton_king: { name: 'Molandak', emoji: '👑', image: `${MONSTER_IMAGES}/skeleton_king.webp` },
    fallen: { name: 'Bee', emoji: '👻', image: `${MONSTER_IMAGES}/fallen.webp` },
    dragon: { name: 'Bullish', emoji: '🐉', image: `${MONSTER_IMAGES}/dragon.webp` }
  },

  monsterFallbackByHp: base.monsterFallbackByHp,

  /** Same Karak hero names; the Monad theme has no hero portraits, so the emoji is shown. */
  heroes: heroesWithoutImages
};
