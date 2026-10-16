import { test, expect } from '@playwright/test';
import { v4 as uuidv4 } from 'uuid';
import { GameHelpers } from '../utils/game-helpers';
import { snap } from '../utils/screens';
import {
  apiGame, apiPlayer, endTurn, hasMoveMarker, moveTo, placeAt, position, packItemId, packTypes, startGame, stopTestMode, strikeWith, switchTo,
  takeReward, turnNumber, waitForFight, waitForFightClosed, watchPage, END_TURN,
} from '../utils/hotseat';

/**
 * The base-game heroes the showcase spec does not play: Swordsman, Warlock, Wizard, Oracle.
 * (Warrior and Thief are in two-player-game.spec.ts.) Each test: hero A against a hero-less B,
 * the first tile placed is a room whose monster A fights at once.
 *
 * Fight arithmetic: 2 dice + weapons + bonuses; more than the monster's strength wins, equal is a draw.
 */
const SPEC = 'base-heroes';

test.describe('Base heroes', () => {
  test.beforeEach(async ({ page }) => watchPage(page));
  test.afterEach(async ({ request }) => stopTestMode(request));

  test('Swordsman: Combat Training rerolls a 1, Unstoppable keeps the turn after a 6', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      // 1 → rerolled → 3, and 6: 9 against the rat's 5, a win with a 6
      diceRolls: [1, 3, 6],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_rat', 'giant_rat'],
      playerConfigs: { [a]: { hero: 'swordsman' }, [b]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await expect(page.getByTestId('combat-training-note')).toBeVisible();
    await expect(page.getByTestId('unstoppable-note')).toBeVisible();
    await expect(page.locator('.victory-title')).toBeVisible();
    await snap(page, SPEC, 'swordsman-win-unstoppable', { phone: true });

    console.log('[spec] take the dagger: the turn goes on');
    await takeReward(page);
    await waitForFightClosed(page);
    expect(await turnNumber(page)).toBe(1);
    await expect(page.locator(END_TURN).first()).toBeVisible();
    expect((await apiGame(request, gameId)).state.currentPlayerId).toBe(a);
    expect(await packTypes(request, gameId, a)).toEqual(['dagger']);
    // back on the field the reason is said again (players asked why the turn did not end)
    await expect(page.getByTestId('action-notice')).toContainText('Unstoppable: a 6 was rolled');
    await snap(page, SPEC, 'swordsman-turn-goes-on');
    await endTurn(page);
  });

  test('Warlock: Sacrifice turns a draw into a win, Magic Swap trades places', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      // 2 + 3 = 5 ties the rat; Sacrifice +1 → 6 wins
      diceRolls: [2, 3],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_rat', 'giant_rat'],
      playerConfigs: { [a]: { hero: 'warlock' }, [b]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await expect(page.getByTestId('sacrifice-hp')).toBeVisible();
    await snap(page, SPEC, 'warlock-sacrifice-offer', { phone: true });
    await page.getByTestId('sacrifice-hp').click();
    await snap(page, SPEC, 'warlock-sacrifice-picked');
    // "Fight, win and pick up": the win is settled and the reward taken in one step
    await strikeWith(page, []);
    await waitForFightClosed(page);
    expect((await apiPlayer(request, gameId, a)).hp).toBe(4);
    expect(await packTypes(request, gameId, a)).toEqual(['dagger']);
    await expect(page.locator('.chronicle')).toContainText(/Sacrifice/, { timeout: 8000 });

    console.log('[spec] B passes, then A swaps places with B');
    await switchTo(page, b);
    await endTurn(page);
    await switchTo(page, a);
    const before = (await apiGame(request, gameId)).field.playerPositions;
    await page.getByTestId('magic-swap').click();
    await expect(page.getByTestId('magic-swap-banner')).toBeVisible();
    await snap(page, SPEC, 'warlock-magic-swap-banner', { phone: true });
    await page.getByTestId(`player-figure-${b}`).click();
    await expect.poll(async () => (await apiGame(request, gameId)).field.playerPositions[a], { timeout: 10000 }).toBe(before[b]);
    expect((await apiGame(request, gameId)).field.playerPositions[b]).toBe(before[a]);
    await snap(page, SPEC, 'warlock-swapped');
  });

  test('Wizard: Magical Affinity keeps the fireball spent in a fight', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      // 2 + 3 = 5 ties the rat; the fireball +1 → 6 wins and stays in the pack
      diceRolls: [2, 3],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_rat', 'giant_rat'],
      playerConfigs: { [a]: { hero: 'wizard', inventory: ['fireball'] }, [b]: {} },
    });
    const fireball = await packItemId(request, gameId, a, 'fireball');

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await snap(page, SPEC, 'wizard-spell-offer', { phone: true });
    await page.locator(`.consumable-row .item-chip.selectable[data-item-id="${fireball}"]`).click();
    await snap(page, SPEC, 'wizard-fireball-picked');
    await strikeWith(page, []);
    await waitForFightClosed(page);
    expect(await packTypes(request, gameId, a)).toEqual(['dagger', 'fireball']);
  });

  test('Wizard: Astral Walking steps through a wall', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      // no rooms, no fights: a straight corridor north of the start, and a crossing at 1,-1 next to it
      // with the corridor's wall between them
      tileSequence: ['twoSideStraight', 'fourSide', 'fourSide', 'fourSide'],
      playerConfigs: { [a]: { hero: 'wizard' }, [b]: {} },
    });

    await placeAt(page, 0, -1);        // the corridor: doors north and south only
    await moveTo(page, 0, 0);
    await placeAt(page, 1, 0);
    await placeAt(page, 1, -1);        // east of the corridor, a wall between
    expect(await position(request, gameId, a)).toBe('1,-1');
    // four steps spent: B passes, the next turn A stands next to the wall
    await switchTo(page, b);
    await endTurn(page);
    await switchTo(page, a);
    const moveTo01 = (await apiGame(request, gameId)).state.availablePlaces.moveTo as string[];
    expect(moveTo01).toContain('0,-1');
    await expect(hasMoveMarker(page, 0, -1)).toBeVisible();
    await snap(page, SPEC, 'wizard-astral-marker', { phone: true });

    await moveTo(page, 0, -1);
    await expect.poll(() => position(request, gameId, a), { timeout: 8000 }).toBe('0,-1');
  });

  test('Oracle: Fateweaver picks one of two tokens, Farseeing adds 1 on the first move', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      // the rat (5) is picked over the mummy (7): 2 + 3 + Farseeing 1 = 6 wins
      diceRolls: [2, 3],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_rat', 'mummy', 'giant_rat'],
      playerConfigs: { [a]: { hero: 'oracle' }, [b]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    const dialog = page.getByTestId('fateweaver-dialog');
    await expect(dialog).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-testid^="fateweaver-item-"]')).toHaveCount(2);
    await snap(page, SPEC, 'oracle-fateweaver', { phone: true });
    // the bag order puts the rat first
    await page.getByTestId('fateweaver-item-0').click();

    await waitForFight(page);
    await expect(page.getByTestId('farseeing-note')).toBeVisible();
    await expect(page.locator('.victory-title')).toBeVisible();
    await snap(page, SPEC, 'oracle-farseeing-win');
    await takeReward(page);
    await waitForFightClosed(page);
    expect(await packTypes(request, gameId, a)).toEqual(['dagger']);
  });
});
