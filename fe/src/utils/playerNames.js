import { heroName } from '@/i18n/names';

/**
 * One name per player for every place it is shown (party rail, chronicle, banner, plaques, choices):
 * the hero's short name ("Horan"), else the username, else "Adventurer". Players who would share a name
 * are told apart by their seat: "Adventurer I", "Adventurer II".
 * @param {Object} player - a player of the game state
 * @param {Object[]} players - all players, in seat order
 */
const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI'];
const toRoman = (n) => ROMAN[n - 1] || String(n);

export const playerDisplayName = (player, players = []) => {
  if (!player) return heroName(null, 'short');
  // Lord Karak keeps his own name ("Horan"): the crown on his badge says what he became, and the chronicle's
  // past lines do not turn into "Lord Karak finds a corridor"
  const base = (p) => (p.hero ? heroName(p.hero, 'short') : (p.username || heroName(null, 'short')));
  const name = base(player);
  const same = players.filter((p) => base(p) === name);
  if (same.length < 2) return name;
  const seat = same.findIndex((p) => p.id === player.id);
  return seat >= 0 ? `${name} ${toRoman(seat + 1)}` : name;
};
