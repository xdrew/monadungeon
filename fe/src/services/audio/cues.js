/**
 * What the game can play: sound cues (fe/public/audio/sfx, made by tools/audio) and music tracks
 * (fe/public/audio/music). A cue with variants has files <id>-1.mp3 … <id>-n.mp3, one picked at random.
 * Add a cue: an entry in tools/audio/assets.json, `python -m tools.audio generate --only <id>`, then here.
 */
const SFX_VARIANTS = {
  ui_click: 3, step: 3, dice_roll: 2,
};

export const SFX = [
  'ui_click', 'ui_toggle', 'ui_open', 'ui_close', 'ui_refused', 'notice',
  'tile_draw', 'tile_place', 'tile_rotate', 'step', 'teleport', 'fountain_heal', 'chest_open', 'chest_locked',
  'item_pickup', 'treasure', 'key', 'item_drop',
  'dice_roll', 'hit_win', 'hit_lose', 'draw', 'monster_roar', 'dragon_roar', 'stunned', 'reincarnate',
  'spell_fireball', 'spell_teleport', 'dark_thorn', 'frost_fist', 'curse', 'curse_lifted', 'sneak',
  'end_turn', 'your_turn', 'turn_tick', 'table_join', 'game_start', 'duel_start',
];

/** The files of a cue. */
export const sfxFiles = (id) => {
  const n = SFX_VARIANTS[id] || 1;
  if (n === 1) return [`/audio/sfx/${id}.mp3`];
  return Array.from({ length: n }, (_, i) => `/audio/sfx/${id}-${i + 1}.mp3`);
};

/** Looping tracks by situation; several files alternate. */
export const TRACKS = {
  lobby: ['/audio/music/lobby.mp3'],
  explore: ['/audio/music/explore-1.mp3', '/audio/music/explore-2.mp3'],
  fight: ['/audio/music/fight.mp3'],
  dragon: ['/audio/music/dragon.mp3'],
};

/** One-shot musical cues; the loop ducks under them. */
export const STINGS = {
  victory: '/audio/music/victory_sting.mp3',
  defeat: '/audio/music/defeat_sting.mp3',
  game_over: '/audio/music/game_over.mp3',
  lord_karak: '/audio/music/lord_karak.mp3',
};
