import { t } from '@/i18n';
import { monsterName } from '@/i18n/names';
import { ref } from 'vue';
import { audio } from '@/services/audio/audioEngine';

// Action types that represent visible game-affecting actions
const VISUALIZABLE_TYPES = new Set([
  'place_tile',
  'move_player',
  'battle_detected',
  'finalize_battle',
  'pickup_attempt',
  'pickup_result',
  'pickup_with_replace',
  'treasure_collected',
  'chest_pickup_attempt',
  'chest_pickup_result',
  'end_turn',
]);

// Base delays per action category (ms)
const BASE_DELAYS = {
  place_tile: 1500,
  move_player: 1000,
  portal: 1500,
  magic_swap: 1500,
  lightning: 1200,
  battle: 2000,
  cursed_room: 2000,
  pickup: 800,
  end_turn: 500,
};

// The AI's jumps: spells and skills that carry its hero (the AI logs them as its reasoning)
const JUMP_TYPES = new Set(['portal', 'magic_swap', 'lightning']);
function jumpStep(action) {
  const d = action.details || {};
  // Warrior "Double Attack": the reroll is a fight step of its own, its new dice and outcome after the first ones
  if (action.type === 'ai_reasoning' && d.decision === 'use_skill' && d.skill === 'double_attack' && d.result?.success
    && d.result?.response?.battleInfo) {
    const bi = d.result.response.battleInfo;
    return { type: 'battle_detected', details: { battleInfo: bi, battleResult: bi.result, reroll: true } };
  }
  if (action.type !== 'ai_reasoning' || !d.to || !d.result?.success) return action;
  if (d.decision === 'use_spell' && d.spell === 'teleport') {
    return { type: 'portal', details: { result: { to: d.to } } };
  }
  if (d.decision === 'use_skill' && d.skill === 'magic_swap') {
    return { type: 'magic_swap', details: { result: { to: d.to, swapWith: d.from ? { playerId: d.target, to: d.from } : null } } };
  }
  if (d.decision === 'use_skill' && d.skill === 'lightning_attack') {
    return { type: 'lightning', details: { result: { to: d.to } } };
  }
  return action;
}

function getActionCategory(type) {
  if (type === 'place_tile') return 'place_tile';
  if (type === 'move_player' || JUMP_TYPES.has(type)) return 'move_player';
  if (type === 'battle' || type === 'battle_detected' || type === 'finalize_battle') return 'battle';
  if (type === 'end_turn') return 'end_turn';
  if (type === 'cursed_room') return 'cursed_room';
  return 'pickup';
}

function getActionLabel(action) {
  switch (action.type) {
    case 'place_tile': {
      const pos = action.details?.result?.position;
      return pos ? t('playback.placedAt', { pos }) : t('playback.placed');
    }
    case 'move_player': {
      const to = action.details?.result?.to;
      return to ? t('playback.movedTo', { pos: to }) : t('playback.moved');
    }
    case 'battle': {
      // Double Attack: the same fight, rolled again
      if (action.reroll) return `${t('skills.double_attack.name')} — ${getActionLabel({ ...action, reroll: false })}`;
      const result = action.battleResult;
      const params = { dmg: action.totalDamage || 0, hp: action.monsterHP || 0 };
      const monster = action.monsterType ? monsterName(action.monsterType, 'name', params.hp) : '';
      if (result === 'win') return monster ? t('playback.wonVs', { ...params, monster }) : t('playback.won', params);
      if (result === 'lose') return monster ? t('playback.lostVs', { ...params, monster }) : t('playback.lost', params);
      if (result === 'draw') return monster ? t('playback.drawVs', { ...params, monster }) : t('playback.draw', params);
      return t('playback.battle');
    }
    case 'pickup_attempt':
    case 'pickup_result':
    case 'pickup_with_replace':
      return t('playback.pickedUp');
    case 'treasure_collected':
      return t('playback.treasure');
    case 'chest_pickup_attempt':
    case 'chest_pickup_result':
      return t('playback.chest');
    case 'portal':
      return t('playback.portal', { pos: action.details?.result?.to || '?' });
    case 'magic_swap':
      return t('playback.magicSwap', { pos: action.details?.result?.to || '?' });
    case 'lightning':
      return t('playback.lightning', { pos: action.details?.result?.to || '?' });
    case 'end_turn':
      return t('playback.turnEnded');
    case 'cursed_room':
      return action.event?.alreadyCursed ? t('playback.cursedRoomAlready') : t('playback.cursedRoom', { roll: action.event?.roll });
    default:
      return action.type;
  }
}

function getActionIcon(type) {
  switch (type) {
    case 'place_tile': return '\u{1F537}'; // 🔷
    case 'move_player': return '\u{1F45F}'; // 👟
    case 'battle': return '\u2694\uFE0F'; // ⚔️
    case 'pickup_attempt':
    case 'pickup_result':
    case 'pickup_with_replace':
      return '\u{1F48E}'; // 💎
    case 'treasure_collected':
    case 'chest_pickup_attempt':
    case 'chest_pickup_result':
      return '\u{1F5C3}\uFE0F'; // 🗃️
    case 'end_turn': return '\u23F9\uFE0F'; // ⏹️
    default: return '';
  }
}

export function useAiTurnPlayback() {
  const isPlayingBack = ref(false);
  const currentStepIndex = ref(0);
  const totalSteps = ref(0);
  const playbackSpeed = ref(1);
  const currentAction = ref(null);

  let skipRequested = false;
  let currentResolve = null;

  /**
   * Filter raw AI actions to only game-affecting, visualizable ones.
   * Merges battle_detected + finalize_battle into a single battle step.
   */
  function filterActions(rawActions) {
    if (!rawActions || !Array.isArray(rawActions)) return [];

    // the AI's jumps get steps of their own (else its figure just turns up elsewhere): Portal of Healing on
    // itself, Magic Swap (both figures trade places), Lightning Attack (onto the monster, before the fight)
    const visualizable = rawActions.map(jumpStep).filter(a => VISUALIZABLE_TYPES.has(a.type) || JUMP_TYPES.has(a.type));

    // Merge battle_detected + finalize_battle into single battle steps
    const merged = [];
    for (let i = 0; i < visualizable.length; i++) {
      const action = visualizable[i];

      if (action.type === 'battle_detected') {
        const bi = action.details?.battleInfo || {};
        const battleResult = bi.result || action.details?.battleResult || 'unknown';
        // Look ahead for finalize_battle (consumable usage)
        const next = visualizable[i + 1];
        if (next && next.type === 'finalize_battle') {
          const finalizeData = next.details?.result?.response || {};
          const finalBi = finalizeData.battleResult || {};
          merged.push({
            type: 'battle',
            reroll: action.details?.reroll === true,
            battleResult: finalBi.result || battleResult,
            monsterType: finalBi.monsterType || bi.monsterType || '',
            totalDamage: finalBi.totalDamage || bi.totalDamage || 0,
            monsterHP: finalBi.monster || bi.monster || 0,
            diceResults: finalBi.diceResults || bi.diceResults || [],
            position: finalBi.position || bi.position || '',
            details: { ...action.details, finalizeResult: next.details?.result },
            label: '',
            icon: '',
          });
          i++; // skip the finalize_battle
          continue;
        }
        // battle_detected without finalize — use battleInfo directly
        merged.push({
          type: 'battle',
          reroll: action.details?.reroll === true,
          battleResult,
          monsterType: bi.monsterType || '',
          totalDamage: bi.totalDamage || 0,
          monsterHP: bi.monster || 0,
          diceResults: bi.diceResults || [],
          position: bi.position || '',
          details: action.details,
          label: '',
          icon: '',
        });
        continue;
      }

      if (action.type === 'finalize_battle') {
        // Standalone finalize (no preceding battle_detected)
        const finalizeData = action.details?.result?.response || {};
        const fb = finalizeData.battleResult || {};
        merged.push({
          type: 'battle',
          battleResult: fb.result || 'unknown',
          monsterType: fb.monsterType || '',
          totalDamage: fb.totalDamage || 0,
          monsterHP: fb.monster || 0,
          diceResults: fb.diceResults || [],
          position: fb.position || '',
          details: action.details,
          label: '',
          icon: '',
        });
        continue;
      }

      // Also skip duplicate pickup actions — keep only the first pickup per position
      merged.push({ ...action });
    }

    // Deduplicate consecutive pickup actions at the same position
    const deduped = [];
    for (let i = 0; i < merged.length; i++) {
      const action = merged[i];
      const category = getActionCategory(action.type);
      if (category === 'pickup' && i > 0) {
        const prev = deduped[deduped.length - 1];
        if (prev && getActionCategory(prev.type) === 'pickup') {
          // Skip duplicate pickup
          continue;
        }
      }
      deduped.push(action);
    }

    const withRooms = placeRoomRolls(deduped, rawActions);

    // Enrich with labels and icons
    return withRooms.map(action => ({
      ...action,
      label: action.label || getActionLabel(action),
      icon: action.icon || getActionIcon(action.type),
    }));
  }

  /**
   * Karak: Regent — the turn's Cursed Room rolls (the AI's `room_events`, in order) as steps of their own, each
   * right after the step that entered its room; a roll with no such step (a hero swapped onto the room) before
   * the end of the turn.
   */
  function placeRoomRolls(steps, rawActions) {
    const events = rawActions.find(a => a.type === 'room_events')?.details?.events || [];
    if (events.length === 0) return steps;
    const result = [...steps];
    let from = 0;
    for (const event of events) {
      const roll = { type: 'cursed_room', event, playerId: event.playerId, position: event.position, label: '', icon: '' };
      const at = result.findIndex((step, i) => i >= from && step.type === 'move_player' && step.details?.result?.to === event.position);
      if (at >= 0) {
        result.splice(at + 1, 0, roll);
        from = at + 2;
        continue;
      }
      const end = result.findIndex((step, i) => i >= from && step.type === 'end_turn');
      const index = end >= 0 ? end : result.length;
      result.splice(index, 0, roll);
      from = index + 1;
    }
    return result;
  }

  /**
   * A played-back fight sounds like one on the fight scene: the dice, then the blow. Tiles, steps and
   * pickups need nothing here: they change the state, and the state's changes sound (useGameSounds).
   */
  function playFightSounds(action) {
    audio.cue('dice_roll');
    const result = String(action.battleResult || '').toLowerCase();
    const blow = { win: 'hit_win', lose: 'hit_lose', draw: 'draw' }[result];
    if (blow) setTimeout(() => audio.cue(blow), 700 / playbackSpeed.value);
  }

  /**
   * Play filtered actions step by step with delays.
   * callbacks: { onPlaceTile, onMovePlayer, onBattle, onCursedRoom, onPickup, onEndTurn }
   */
  async function playActions(filteredActions, callbacks) {
    if (filteredActions.length === 0) return;

    isPlayingBack.value = true;
    currentStepIndex.value = 0;
    totalSteps.value = filteredActions.length;
    skipRequested = false;

    for (let i = 0; i < filteredActions.length; i++) {
      if (skipRequested) break;

      const action = filteredActions[i];
      currentStepIndex.value = i;
      currentAction.value = action;

      // Call the appropriate callback
      const category = getActionCategory(action.type);
      try {
        switch (category) {
          case 'place_tile':
            if (callbacks.onPlaceTile) await callbacks.onPlaceTile(action);
            break;
          case 'move_player':
            if (callbacks.onMovePlayer) await callbacks.onMovePlayer(action);
            if (action.type === 'portal' || action.type === 'magic_swap') audio.cue('spell_teleport');
            break;
          case 'battle':
            if (callbacks.onBattle) await callbacks.onBattle(action);
            playFightSounds(action);
            break;
          case 'end_turn':
            if (callbacks.onEndTurn) await callbacks.onEndTurn(action);
            break;
          case 'cursed_room':
            if (callbacks.onCursedRoom) await callbacks.onCursedRoom(action);
            if (!action.event?.alreadyCursed) audio.cue('dice_roll');
            break;
          default:
            if (callbacks.onPickup) await callbacks.onPickup(action);
            break;
        }
      } catch (err) {
        console.error('Error during AI playback step:', err);
      }

      // Wait before next step (unless skipped or last action)
      if (!skipRequested && i < filteredActions.length - 1) {
        const delay = (BASE_DELAYS[action.type] || BASE_DELAYS[category] || 800) / playbackSpeed.value;
        await new Promise(resolve => {
          currentResolve = resolve;
          setTimeout(resolve, delay);
        });
        currentResolve = null;
      }
    }

    // Cleanup
    isPlayingBack.value = false;
    currentStepIndex.value = 0;
    totalSteps.value = 0;
    currentAction.value = null;
  }

  function setSpeed(speed) {
    playbackSpeed.value = Number(speed);
  }

  function skip() {
    skipRequested = true;
    // Resolve any pending delay immediately
    if (currentResolve) {
      currentResolve();
      currentResolve = null;
    }
  }

  return {
    isPlayingBack,
    currentStepIndex,
    totalSteps,
    playbackSpeed,
    currentAction,
    filterActions,
    playActions,
    setSpeed,
    skip,
  };
}
