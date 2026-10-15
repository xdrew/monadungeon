/**
 * The chronicle: game-log actions (database turns and AI playback actions) told as plain
 * sentences with the hero as the subject — "Aderyn finds a new corridor." No emoji, no HTML.
 * Used by the HUD chronicle (last lines) and the Journal drawer (every turn).
 */
import { getMonsterName } from '@/theme';

const humanize = (value, fallback = '') => (value ? String(value).replace(/_/g, ' ') : fallback);
const monsterName = (key) => (key ? getMonsterName(String(key).toLowerCase()) : 'monster');

const ARTICLE_LESS = /^(a|an|the|some)\s/i;
const withArticle = (noun) => {
  if (!noun) return 'something';
  if (ARTICLE_LESS.test(noun)) return noun;
  return /^[aeiou]/i.test(noun) ? `an ${noun}` : `a ${noun}`;
};

const typeOf = (action) => action?.type || action?.action || '';
const detailsOf = (action) => action?.details || action?.additionalData || {};

/** The item a pickup is about, from whichever field the backend or the AI filled. */
const pickedItemType = (details, siblings) => {
  const fromDetails = details?.itemType || details?.itemName || details?.reward?.type || details?.item?.type
    || details?.apiResult?.response?.item?.type || details?.monster?.type;
  if (fromDetails) return fromDetails;
  // Database pick_item rows carry no type: the reward is the one of the monster fought this turn
  const fight = (siblings || []).find((a) => typeOf(a) === 'fight_monster');
  return detailsOf(fight)?.monster?.type || null;
};

/** Hero-skill notes of a fight, in the order a reader cares about. */
const fightNotes = (d) => {
  const notes = [];
  if (d.lightningAttack) notes.push('Lightning Attack');
  if (d.rerolled) notes.push('Double Attack');
  if (d.onesRerolled > 0) notes.push('Combat Training');
  if (d.onesAsSixes > 0) notes.push('Bear Attack');
  if (d.berserk > 0) notes.push(`Berserk +${d.berserk}`);
  if (d.hpSacrificed) notes.push('Sacrifice');
  if (d.farseeing) notes.push('Farseeing');
  if (d.spellsKept) notes.push('Magical Affinity');
  return notes;
};

/**
 * One action as a sentence, or null for actions the chronicle skips (duplicates, internals).
 * @param {object} action
 * @param {string} who the acting hero's name
 * @param {(playerId: string) => string} nameOf
 * @param {object[]} [siblings] the other actions of the same turn
 * @returns {string|null}
 */
export function describeAction(action, who, nameOf, siblings = []) {
  const type = typeOf(action);
  const d = detailsOf(action);

  switch (type) {
    case 'tile_placed':
    case 'place_tile':
      return `${who} finds a new corridor.`;
    case 'tile_rotated':
    case 'rotate_tile':
      return null;
    case 'player_moved':
    case 'move':
      if (d.sneakedPastMonster) return `${who} sneaks past the ${monsterName(d.monster?.name)}.`;
      if (d.throughWall) return `${who} walks through a wall to ${d.to || 'a known room'}.`;
      if (d.strategy === 'moving toward item') return `${who} heads for the loot.`;
      return `${who} moves on.`;
    case 'magic_swap':
      return `${who} trades places with another hero.`;
    case 'curse':
      return d.kept ? `The Curse of Karak stays on ${nameOf(d.target)}.` : `${who} lays the Curse of Karak on ${nameOf(d.target)}.`;
    case 'use_spell':
      if (d.spell === 'teleport') {
        return d.self ? `${who} steps through a Portal of Healing.` : `${who} sends ${nameOf(d.target)} through a Portal of Healing.`;
      }
      if (d.spell === 'dark_thorn') {
        const tail = d.reincarnatedTo ? ', who rises again at a fountain' : d.stunned ? ', who falls stunned' : '';
        return `${who} pierces ${nameOf(d.target)} with a Dark Thorn${tail}.`;
      }
      return `${who} casts a spell.`;
    case 'cursed_room':
      return d.cursed
        ? `${nameOf(d.player)} rolls ${d.roll} in a cursed room and is cursed.`
        : `${nameOf(d.player)} rolls ${d.roll} in a cursed room and walks free.`;
    case 'duel': {
      const totals = `${d.challengerTotal ?? '?'} against ${d.opponentTotal ?? '?'}`;
      if (d.result === 'tie') return `${nameOf(d.challenger)} and ${nameOf(d.opponent)} duel to a draw, ${totals}.`;
      const winner = d.result === 'challenger' ? d.challenger : d.opponent;
      const loot = d.loot?.type ? ` and takes ${withArticle(humanize(d.loot.type))}` : '';
      return `${nameOf(winner)} wins the duel, ${totals}${loot}.`;
    }
    case 'duel_loot':
      return `${who} takes ${withArticle(humanize(d.item, 'item'))} from ${nameOf(d.from)}.`;
    case 'tireless':
      return `${who} leaves the ${humanize(d.leftItem?.type, 'loot')} behind and keeps moving.`;
    case 'lightning_attack':
      return `${who} strikes the ${monsterName(d.monster?.name)} with lightning.`;
    case 'retreat':
      return `${who} retreats to ${d.to || 'safety'}.`;
    case 'sprint':
      return `${who} breaks into a sprint.`;
    case 'lord_karak':
      return `${nameOf(d.player)} becomes Lord Karak.`;
    case 'listen': {
      const heard = d.item ? `the ${d.item.hp > 0 ? monsterName(d.item.name) : humanize(d.item.name)}` : 'only silence';
      return `${who} listens at a door and hears ${heard}.`;
    }
    case 'fateweaver':
      return `${who} weaves fate: the ${humanize(d.chosen?.name || d.chosen?.type, 'token')} is placed.`;
    case 'fight_monster': {
      const name = monsterName(d.monsterType || d.monster?.name || d.monster?.type);
      const total = d.totalDamage ?? d.diceRollDamage;
      const hp = d.monster?.hp ?? d.monsterHP ?? (typeof d.monster === 'number' ? d.monster : null);
      const score = total != null && hp != null ? ` (${total} against ${hp})` : '';
      const notes = fightNotes(d);
      const with_ = notes.length ? `, ${notes.join(', ')}` : '';
      const tail = d.unstoppable ? ' and fights on' : '';
      if (d.result === 'WIN') return `${who} defeats the ${name}${score}${with_}${tail}.`;
      if (d.result === 'LOSE') return `${who} falls to the ${name}${score}${with_}${tail}.`;
      if (d.result === 'DRAW') return `${who} and the ${name} stand even${score}${with_}.`;
      return `${who} faces the ${name}.`;
    }
    case 'immediate_battle_pickup':
    case 'item_picked_up':
    case 'item_picked':
    case 'pick_item': {
      if (d.strategy === 'chest opened') return `${who} opens a chest.`;
      const item = pickedItemType(d, siblings);
      if (item === 'chest') return `${who} finds a chest.`;
      return `${who} picks up ${withArticle(humanize(item, 'treasure'))}.`;
    }
    case 'chest_locked':
      return `${who} finds a locked chest.`;
    case 'chest_opened':
      return `${who} opens a chest.`;
    case 'item_dropped':
    case 'drop_item':
      return `${who} drops ${withArticle(humanize(d.item, 'item'))}.`;
    case 'spell_used':
      return `${who} casts ${humanize(d.spell, 'a spell')}.`;
    case 'use_teleport':
      return `${who} teleports.`;
    case 'use_hero_ability':
      if (d.ability === 'reincarnation') return `${who} rises again at a healing fountain.`;
      return `${who} uses a hero skill.`;
    case 'player_healed':
      return `${who} heals ${d.amount || 1} at the fountain.`;
    case 'player_stunned':
      return `${who} is stunned.`;
    case 'ai_decision':
      return null;
    // Duplicates of fight_monster / pickups, AI internals and turn bookkeeping
    case 'tile_picked':
    case 'pick_tile':
    case 'battle_started':
    case 'battle_detected':
    case 'battle_analysis':
    case 'battle_result':
    case 'battle_won':
    case 'battle_lost':
    case 'ai_battle_analysis':
    case 'battle_finalized':
    case 'item_picked_up_manual':
    case 'turn_ended_manual':
    case 'turn_ended':
    case 'end_turn':
    case 'ai_thinking':
      return null;
    default:
      return type ? `${who}: ${humanize(type)}.` : null;
  }
}

/** A fight is logged twice (start and finalize): keep the one with the highest total. */
const dedupeFights = (actions) => {
  const fights = actions.filter((a) => typeOf(a) === 'fight_monster');
  if (fights.length < 2) return actions;
  const best = fights.reduce((max, a) => ((detailsOf(a).totalDamage || 0) > (detailsOf(max).totalDamage || 0) ? a : max));
  return actions.filter((a) => typeOf(a) !== 'fight_monster' || a === best);
};

/**
 * Every turn with its sentences, oldest first.
 * @param {object[]} turns `{ turnId, turnNumber, playerId, actions }` as GameView keeps them
 * @param {(playerId: string) => string} nameOf
 * @returns {{ key: string, turnNumber: number, playerId: string, name: string, lines: string[] }[]}
 */
export function chronicleTurns(turns, nameOf) {
  return [...(turns || [])]
    .sort((a, b) => (a.turnNumber || 0) - (b.turnNumber || 0))
    .map((turn) => {
      const actions = dedupeFights(Array.isArray(turn.actions) ? turn.actions : []);
      const who = nameOf(turn.playerId);
      const lines = actions.map((a) => describeAction(a, who, nameOf, actions)).filter(Boolean);
      return { key: String(turn.turnId || turn.turnNumber), turnNumber: turn.turnNumber, playerId: turn.playerId, name: who, lines };
    });
}

/**
 * The last `limit` sentences across turns, oldest first.
 * @returns {{ key: string, text: string }[]}
 */
export function chronicleTail(turns, nameOf, limit = 4) {
  const all = chronicleTurns(turns, nameOf).flatMap((t) => t.lines.map((text, i) => ({ key: `${t.key}-${i}`, text })));
  return all.slice(-limit);
}
