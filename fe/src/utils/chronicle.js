/**
 * The chronicle: game-log actions (database turns and AI playback actions) told as plain
 * sentences with the hero as the subject — "Aderyn finds a new corridor." No emoji, no HTML.
 * Every sentence is one chronicle.* key; monsters and items come in the grammatical form the
 * sentence needs (acc / ins, see fe/src/i18n/names.js). Used by the HUD chronicle and the Journal.
 */
import { t } from '@/i18n';
import { monsterName, itemName } from '@/i18n/names';

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

/** Hero-skill notes of a fight, in the order a reader cares about (skill names in the current language). */
const fightNotes = (d) => {
  const notes = [];
  if (d.lightningAttack) notes.push(t('skills.lightning_attack.name'));
  if (d.rerolled) notes.push(t('skills.double_attack.name'));
  if (d.onesRerolled > 0) notes.push(t('skills.combat_training.name'));
  if (d.onesAsSixes > 0) notes.push(t('skills.bear_attack.name'));
  if (d.berserk > 0) notes.push(`${t('skills.berserk.name')} +${d.berserk}`);
  if (d.hpSacrificed) notes.push(t('skills.sacrifice.name'));
  if (d.farseeing) notes.push(t('skills.farseeing.name'));
  if (d.spellsKept) notes.push(t('skills.magical_affinity.name'));
  return notes;
};

/** "Aderyn defeats the Giant Rat (9 against 5, Double Attack)." */
const fightSentence = (who, d) => {
  const key = d.monsterType || d.monster?.name || d.monster?.type;
  const total = d.totalDamage ?? d.diceRollDamage;
  const hp = d.monster?.hp ?? d.monsterHP ?? (typeof d.monster === 'number' ? d.monster : null);
  const extras = [];
  if (total != null && hp != null) extras.push(t('chronicle.score', { total, hp }));
  extras.push(...fightNotes(d));
  const tail = extras.length ? ` (${extras.join(', ')})` : '';
  let sentence;
  if (d.result === 'WIN') sentence = t('chronicle.defeats', { who, monster: monsterName(key, 'acc') });
  else if (d.result === 'LOSE') sentence = t('chronicle.fallsTo', { who, monster: monsterName(key, 'ins') });
  else if (d.result === 'DRAW') sentence = t('chronicle.draw', { who, monster: monsterName(key, 'ins') });
  else return t('chronicle.faces', { who, monster: monsterName(key, 'ins') });
  const fightsOn = d.unstoppable ? ` ${t('chronicle.fightsOn', { who })}` : '';
  return `${sentence}${tail}.${fightsOn}`;
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
      return t('chronicle.corridor', { who });
    case 'player_moved':
    case 'move':
      if (d.sneakedPastMonster) return t('chronicle.sneaks', { who, monster: monsterName(d.monster?.name, 'acc') });
      if (d.throughWall) return t('chronicle.throughWall', { who, to: d.to || '?' });
      if (d.strategy === 'moving toward item') return t('chronicle.headsForLoot', { who });
      return t('chronicle.movesOn', { who });
    case 'magic_swap':
      return t('chronicle.magicSwap', { who });
    case 'curse':
      return d.kept ? t('chronicle.curseStays', { target: nameOf(d.target) }) : t('chronicle.curseLaid', { who, target: nameOf(d.target) });
    case 'use_spell':
      if (d.spell === 'teleport') {
        return d.self ? t('chronicle.portalSelf', { who }) : t('chronicle.portalOther', { who, target: nameOf(d.target) });
      }
      if (d.spell === 'dark_thorn') {
        const key = d.reincarnatedTo ? 'chronicle.darkThornRises' : d.stunned ? 'chronicle.darkThornStunned' : 'chronicle.darkThorn';
        return t(key, { who, target: nameOf(d.target) });
      }
      return t('chronicle.castsSpell', { who });
    case 'cursed_room':
      return t(d.cursed ? 'chronicle.cursedRoomCursed' : 'chronicle.cursedRoomSafe', { who: nameOf(d.player), roll: d.roll });
    case 'duel': {
      const params = { a: d.challengerTotal ?? '?', b: d.opponentTotal ?? '?' };
      if (d.result === 'tie') return t('chronicle.duelDraw', { ...params, first: nameOf(d.challenger), second: nameOf(d.opponent) });
      const winner = nameOf(d.result === 'challenger' ? d.challenger : d.opponent);
      return d.loot?.type
        ? t('chronicle.duelWinLoot', { ...params, winner, item: itemName(d.loot.type, 'acc') })
        : t('chronicle.duelWin', { ...params, winner });
    }
    case 'duel_loot':
      return t('chronicle.duelLoot', { who, item: itemName(d.item, 'acc'), from: nameOf(d.from) });
    case 'tireless':
      return t('chronicle.tireless', { who, item: itemName(d.leftItem?.type, 'acc') });
    case 'lightning_attack':
      return t('chronicle.lightning', { who, monster: monsterName(d.monster?.name, 'acc') });
    case 'retreat':
      return t('chronicle.retreat', { who, to: d.to || '?' });
    case 'sprint':
      return t('chronicle.sprint', { who });
    case 'lord_karak':
      return t('chronicle.lordKarak', { who: nameOf(d.player) });
    case 'listen':
      if (!d.item) return t('chronicle.listenSilence', { who });
      return d.item.hp > 0
        ? t('chronicle.listenMonster', { who, monster: monsterName(d.item.name, 'acc', d.item.hp) })
        : t('chronicle.listenItem', { who, item: itemName(d.item.name, 'acc') });
    case 'fateweaver':
      return t('chronicle.fateweaver', { who, token: d.chosen?.hp > 0 ? monsterName(d.chosen.name, 'acc', d.chosen.hp) : itemName(d.chosen?.type || d.chosen?.name, 'acc') });
    case 'fight_monster':
      return fightSentence(who, d);
    case 'immediate_battle_pickup':
    case 'item_picked_up':
    case 'item_picked':
    case 'pick_item': {
      if (d.strategy === 'chest opened') return t('chronicle.opensChest', { who });
      const item = pickedItemType(d, siblings);
      if (item === 'chest') return t('chronicle.findsChest', { who });
      return t('chronicle.picksUp', { who, item: itemName(item || 'treasure', 'acc') });
    }
    case 'chest_locked':
      return t('chronicle.findsLockedChest', { who });
    case 'chest_opened':
      return t('chronicle.opensChest', { who });
    case 'item_dropped':
    case 'drop_item':
      return t('chronicle.drops', { who, item: itemName(d.item, 'acc') });
    case 'spell_used':
      return t('chronicle.casts', { who, spell: itemName(d.spell, 'acc') });
    case 'use_teleport':
      return t('chronicle.teleports', { who });
    case 'use_hero_ability':
      return d.ability === 'reincarnation' ? t('chronicle.rises', { who }) : t('chronicle.usesSkill', { who });
    case 'player_healed':
      return t('chronicle.heals', { who, n: d.amount || 1 }, d.amount || 1);
    case 'player_stunned':
      return t('chronicle.stunned', { who });
    // Duplicates of fight_monster / pickups, AI internals and turn bookkeeping
    case 'tile_picked':
    case 'pick_tile':
    case 'tile_rotated':
    case 'rotate_tile':
    case 'ai_decision':
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
      return type ? t('chronicle.other', { who, action: type.replace(/_/g, ' ') }) : null;
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
  const all = chronicleTurns(turns, nameOf).flatMap((turn) => turn.lines.map((text, i) => ({ key: `${turn.key}-${i}`, text })));
  return all.slice(-limit);
}
