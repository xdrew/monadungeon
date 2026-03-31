import { ref } from 'vue';

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
  battle: 2000,
  pickup: 800,
  end_turn: 500,
};

function getActionCategory(type) {
  if (type === 'place_tile') return 'place_tile';
  if (type === 'move_player') return 'move_player';
  if (type === 'battle' || type === 'battle_detected' || type === 'finalize_battle') return 'battle';
  if (type === 'end_turn') return 'end_turn';
  return 'pickup';
}

function getActionLabel(action) {
  switch (action.type) {
    case 'place_tile': {
      const pos = action.details?.result?.position;
      return pos ? `Placed tile at ${pos}` : 'Placed tile';
    }
    case 'move_player': {
      const to = action.details?.result?.to;
      return to ? `Moved to ${to}` : 'Moved';
    }
    case 'battle': {
      const result = action.battleResult;
      const monster = action.monsterType || '';
      const dmg = action.totalDamage || 0;
      const hp = action.monsterHP || 0;
      const monsterLabel = monster ? monster.replace(/_/g, ' ') : '';
      if (result === 'win') return monsterLabel ? `Won vs ${monsterLabel} (${dmg} > ${hp})` : `Won battle (${dmg} > ${hp})`;
      if (result === 'lose') return monsterLabel ? `Lost vs ${monsterLabel} (${dmg} < ${hp})` : `Lost battle (${dmg} < ${hp})`;
      if (result === 'draw') return monsterLabel ? `Draw vs ${monsterLabel} (${dmg} = ${hp})` : `Draw (${dmg} = ${hp})`;
      return 'Battle';
    }
    case 'pickup_attempt':
    case 'pickup_result':
    case 'pickup_with_replace':
      return 'Picked up item';
    case 'treasure_collected':
      return 'Collected treasure';
    case 'chest_pickup_attempt':
    case 'chest_pickup_result':
      return 'Opening chest';
    case 'end_turn':
      return 'Turn ended';
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

    const visualizable = rawActions.filter(a => VISUALIZABLE_TYPES.has(a.type));

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

    // Enrich with labels and icons
    return deduped.map(action => ({
      ...action,
      label: action.label || getActionLabel(action),
      icon: action.icon || getActionIcon(action.type),
    }));
  }

  /**
   * Play filtered actions step by step with delays.
   * callbacks: { onPlaceTile, onMovePlayer, onBattle, onPickup, onEndTurn }
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
            break;
          case 'battle':
            if (callbacks.onBattle) await callbacks.onBattle(action);
            break;
          case 'end_turn':
            if (callbacks.onEndTurn) await callbacks.onEndTurn(action);
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
        const delay = (BASE_DELAYS[category] || 800) / playbackSpeed.value;
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
