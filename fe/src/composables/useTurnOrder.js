import { computed, watch } from 'vue';
import { heroName } from '@/i18n/names';
import { playerDisplayName } from '@/utils/playerNames';

const ROMAN = [[10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']];

/** 1 → I, 4 → IV, 12 → XII (no upper cap). */
export const toRoman = (n) => {
  let rest = Math.max(1, Math.floor(n));
  let out = '';
  for (const [value, glyph] of ROMAN) {
    while (rest >= value) {
      out += glyph;
      rest -= value;
    }
  }
  return out;
};

/**
 * Turn order of the company, as the backend rotates `Game::$players` by index:
 * numeral I is the round starter (the highest starting roll, ties to the earlier player;
 * players[0] without rolls), then the array order wrapping around.
 *
 * @param {import('vue').Ref<object|null>} gameData
 * @param {(player: object) => boolean} isYou the human player at this screen
 */
export function useTurnOrder(gameData, isYou) {
  const starterId = computed(() => {
    const players = gameData.value?.players || [];
    const rolls = gameData.value?.state?.startingRolls || {};
    let starter = players[0]?.id || null;
    let best = 0;
    for (const p of players) {
      const roll = Number(rolls[p.id] || 0);
      if (roll > best) {
        best = roll;
        starter = p.id;
      }
    }
    return starter;
  });

  const ordered = computed(() => {
    const players = gameData.value?.players || [];
    const from = Math.max(0, players.findIndex((p) => p.id === starterId.value));
    return [...players.slice(from), ...players.slice(0, from)];
  });

  const actingId = computed(() => (gameData.value?.state?.status === 'finished' ? null : gameData.value?.state?.currentPlayerId || null));

  const entries = computed(() => {
    const list = ordered.value;
    const actingIndex = list.findIndex((p) => p.id === actingId.value);
    return list.map((player, index) => {
      let state = 'waiting';
      if (actingIndex >= 0) {
        if (index === actingIndex) state = 'acting';
        else if (list.length > 2 && index === (actingIndex + 1) % list.length) state = 'next';
        else if (index < actingIndex) state = 'done';
      }
      return { player, numeral: toRoman(index + 1), state, isYou: !!isYou(player), name: playerDisplayName(player, list) };
    });
  });

  const acting = computed(() => entries.value.find((e) => e.state === 'acting') || null);

  /** Names of the heroes still to act after the acting one and before the human ([] when the human acts or is next). */
  const waitingFor = computed(() => {
    const list = entries.value;
    const actingIndex = list.findIndex((e) => e.state === 'acting');
    const youIndex = list.findIndex((e) => e.isYou);
    if (actingIndex < 0 || youIndex < 0 || actingIndex === youIndex) return [];
    const names = [];
    for (let i = (actingIndex + 1) % list.length; i !== youIndex; i = (i + 1) % list.length) {
      names.push(list[i].name);
    }
    return names;
  });

  watch(
    () => `${ordered.value.map((p) => p.id).join(',')}|${actingId.value}`,
    () => {
      if (ordered.value.length) {
        console.debug('[hud] turn order', ordered.value.map((p) => p.id), 'acting', actingId.value);
      }
    },
    { immediate: true }
  );

  return { entries, acting, waitingFor };
}

/** "Taia" / "Taia and Lorraine" / "Taia, Valduk and Lorraine" (the word for "and" comes with the locale). */
export const joinNames = (names, and = 'and') => {
  if (names.length <= 1) return names[0] || '';
  return `${names.slice(0, -1).join(', ')} ${and} ${names[names.length - 1]}`;
};
