import { watch } from 'vue';
import { audio } from '@/services/audio/audioEngine';

/**
 * The game's sounds, told from the state: every change of the game (mine, the AI's played back,
 * an online rival's pushed) is a difference between two snapshots, and each kind of difference is one
 * cue. The fight scene sounds its own dice and blows (BattleReportModal), the AI playback its fights
 * (useAiTurnPlayback). Nothing sounds on the first load, on a switch to another game or in a replay.
 */

const SPELL_CUES = {
  fireball: 'spell_fireball', teleport: 'teleport', dark_thorn: 'dark_thorn', frost_fist: 'frost_fist',
};

const itemKey = (item) => item?.type || item?.name || '';

const snapshot = (game) => {
  if (!game?.state) return null;
  const players = {};
  for (const p of game.players || []) {
    const inv = p.inventory || {};
    players[p.id] = {
      hp: p.hp,
      defeated: !!p.defeated,
      cursed: !!p.cursed,
      reincarnating: !!p.awaitingReincarnation,
      keys: (inv.keys || []).length,
      weapons: (inv.weapons || []).length,
      treasures: (inv.treasures || []).length,
      spells: (inv.spells || []).map(itemKey),
    };
  }
  return {
    gameId: game.gameId,
    status: game.state.status,
    currentPlayerId: game.state.currentPlayerId,
    turn: game.state.turn,
    tiles: Object.keys(game.field?.tileOrientations || {}).length,
    positions: { ...(game.field?.playerPositions || {}) },
    players,
    seats: (game.players || []).length,
    lordKarak: !!game.state.lordKarak,
    duelId: game.state.duel?.battleId || game.state.duel?.id || (game.state.duel ? 'on' : null),
  };
};

/** The cues between two snapshots of one game, in the order they happened. */
export function cuesBetween(prev, next, { isMine = () => false } = {}) {
  const cues = [];
  if (prev.status === 'lobby' && next.status !== 'lobby') cues.push('game_start');
  if (next.status === 'lobby' && next.seats > prev.seats) cues.push('table_join');
  if (next.tiles > prev.tiles) cues.push('tile_place');
  // the hero's moves are steps; a teleport is told by the spell it spends
  if (Object.entries(next.positions).some(([id, pos]) => prev.positions[id] && prev.positions[id] !== pos)) {
    cues.push('step');
  }
  for (const [id, p] of Object.entries(next.players)) {
    const was = prev.players[id];
    if (!was) continue;
    if (!was.defeated && p.defeated) cues.push('stunned');
    else if ((was.defeated && !p.defeated) || (was.reincarnating && !p.reincarnating)) cues.push('reincarnate');
    else if (p.hp > was.hp && !p.defeated) cues.push('fountain_heal');
    if (p.treasures > was.treasures) cues.push('chest_open', 'treasure');
    if (p.keys > was.keys) cues.push('key');
    if (p.weapons > was.weapons) cues.push('item_pickup');
    else if (p.weapons < was.weapons) cues.push('item_drop');
    const gone = [...was.spells];
    for (const s of p.spells) {
      const i = gone.indexOf(s);
      if (i >= 0) gone.splice(i, 1);
    }
    if (p.spells.length > was.spells.length) cues.push('item_pickup');
    else for (const s of gone) cues.push(SPELL_CUES[s] || 'spell_teleport');
    if (!was.cursed && p.cursed) cues.push('curse');
    else if (was.cursed && !p.cursed) cues.push('curse_lifted');
  }
  if (next.duelId && next.duelId !== prev.duelId) cues.push('duel_start');
  // the turn number, not the player: a reload after a played-back AI turn can bring the same player back
  const turnEnded = next.turn > prev.turn || (prev.currentPlayerId && next.currentPlayerId !== prev.currentPlayerId);
  if (turnEnded && prev.status !== 'lobby' && next.status !== 'finished') {
    cues.push('end_turn');
    if (isMine(next.currentPlayerId)) cues.push('your_turn');
  }
  return [...new Set(cues)];
}

const STAGGER_MS = 140; // cues of one change follow one another, not on top

/**
 * @param {import('vue').Ref} gameData
 * @param {{ isReplaying: import('vue').Ref<boolean>, isMine: (playerId: string) => boolean }} opts
 */
export function useGameSounds(gameData, { isReplaying, isMine }) {
  let prev = null;
  watch(() => snapshot(gameData.value), (next) => {
    const before = prev;
    prev = next;
    if (!before || !next || before.gameId !== next.gameId || isReplaying?.value) return;
    if (before.status !== 'finished' && next.status === 'finished') audio.sting('game_over');
    if (!before.lordKarak && next.lordKarak) audio.sting('lord_karak');
    cuesBetween(before, next, { isMine }).forEach((id, i) => {
      if (i === 0) audio.cue(id);
      else setTimeout(() => audio.cue(id), i * STAGGER_MS);
    });
  }, { deep: true });
}
