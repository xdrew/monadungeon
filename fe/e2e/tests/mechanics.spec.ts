import { test, expect, Page } from '@playwright/test';
import { v4 as uuidv4 } from 'uuid';
import { GameHelpers } from '../utils/game-helpers';
import { snap } from '../utils/screens';
import {
  API_URL, apiGame, apiPlayer, endTurn, expectNotice, moveTo, packItemId, packTypes, placeAt, position,
  rotateGhost, startGame, stopTestMode, strikeWith, switchTo, takeReward, waitForFight, waitForFightClosed,
  watchPage,
} from '../utils/hotseat';

/**
 * Mechanics the showcase spec does not reach: the Curse of Karak, the Portal of Healing, chests with and
 * without a key, a find the full pack does not want, tile rotation, a fight surviving a reload, and the
 * end of the game with the results and the Hall of fame.
 * Fight arithmetic: 2 dice + weapons + bonuses; more than the monster's strength wins, equal is a draw.
 */
const SPEC = 'mechanics';

const tileItem = (page: Page, pos: string) => page.locator(`.tile[data-position="${pos}"] .tile-items`).first();

test.describe('Mechanics', () => {
  test.beforeEach(async ({ page }) => { test.setTimeout(150000); watchPage(page); });
  test.afterEach(async ({ request }) => stopTestMode(request));

  test('Curse of Karak: the mummy slayer curses the rival, a fountain lifts it', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      // 4 + 4 = 8 beats the mummy (7)
      diceRolls: [4, 4],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['mummy', 'giant_rat'],
      // B has a hero: the rail shows the curse token on a hero badge
      playerConfigs: { [a]: {}, [b]: { hero: 'thief' } },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await expect(page.getByTestId('curse-choice')).toBeVisible();
    await snap(page, SPEC, 'curse-choice', { phone: true });
    await page.getByTestId(`curse-player-${b}`).click();
    await expect(page.getByTestId('curse-choice')).toHaveCount(0, { timeout: 10000 });
    await snap(page, SPEC, 'curse-chosen');
    await takeReward(page);
    await waitForFightClosed(page);
    expect((await apiPlayer(request, gameId, b)).cursed).toBe(true);
    await expect(page.getByTestId('curse-token').first()).toBeVisible();
    await snap(page, SPEC, 'curse-on-rival');

    console.log('[spec] B ends the turn on the start fountain: the curse is lifted');
    await switchTo(page, b);
    expect(await position(request, gameId, b)).toBe('0,0');
    await endTurn(page);
    await expect.poll(async () => (await apiPlayer(request, gameId, b)).cursed, { timeout: 10000 }).toBe(false);
  });

  test('Portal of Healing carries the hero to a fountain', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      tileSequence: ['fourSide', 'fourSide'],
      playerConfigs: { [a]: { inventory: ['teleport'], hp: 2 }, [b]: {} },
    });

    await placeAt(page, 0, -1);
    expect(await position(request, gameId, a)).toBe('0,-1');
    console.log('[spec] cast the portal from the pack, onto the start fountain');
    await page.locator('.inventory-item.spell-item.clickable').first().click();
    await expect(page.locator('.teleport-controls')).toBeVisible();
    await snap(page, SPEC, 'portal-targets', { phone: true });
    await page.locator('.healing-fountain-marker').first().click();
    await expect.poll(() => position(request, gameId, a), { timeout: 10000 }).toBe('0,0');
    expect(await packTypes(request, gameId, a)).toEqual([]);
    await snap(page, SPEC, 'portal-arrived');
  });

  test('Chests: without a key a chest is not offered; with a key it opens', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['treasure_chest', 'giant_rat'],
      playerConfigs: { [a]: {}, [b]: { inventory: ['key'] } },
    });

    // without a key the server does not offer the chest: it just lies there and the turn goes on
    await GameHelpers.placeTile(page, 0, 0);
    await page.waitForTimeout(1500);
    await expect(page.locator('.item-pickup-overlay')).toHaveCount(0);
    await expect(page.locator('.missing-key-dialog')).toHaveCount(0);
    await snap(page, SPEC, 'chest-without-key', { phone: true });
    const chest = await position(request, gameId, a);
    await endTurn(page);

    console.log(`[spec] B walks to the chest at ${chest} with a key`);

    await switchTo(page, b);
    const [cx, cy] = chest.split(',').map(Number);
    await moveTo(page, cx, cy);
    await expect(page.locator('.item-pickup-overlay')).toBeVisible({ timeout: 15000 });
    await snap(page, SPEC, 'chest-with-key', { phone: true });
    await page.locator('.item-pickup-overlay .btn-pickup').click();
    await expect.poll(async () => (await apiPlayer(request, gameId, b)).inventory.treasures?.length || 0, { timeout: 10000 }).toBe(1);
  });

  test('A full pack is not offered a find it does not want; a click still offers it', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      // 3 + 3 + two swords 4 = 10 beats the rat; its dagger is left on the tile
      diceRolls: [3, 3],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_rat', 'giant_rat'],
      playerConfigs: { [a]: { inventory: ['sword', 'sword'] }, [b]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await page.getByTestId('leave-reward').click();
    await waitForFightClosed(page);
    const room = await position(request, gameId, a);
    const [rx, ry] = room.split(',').map(Number);

    await switchTo(page, b);
    await endTurn(page);
    await switchTo(page, a);
    console.log('[spec] out and back onto the dagger: a notice, no plaque');
    await moveTo(page, 0, 0);
    await moveTo(page, rx, ry);
    await expectNotice(page, /no better than what you carry/i);
    await expect(page.locator('.item-pickup-overlay')).toHaveCount(0);
    await snap(page, SPEC, 'find-not-offered');

    await tileItem(page, room).click({ force: true });
    await expect(page.locator('.item-pickup-overlay')).toBeVisible({ timeout: 8000 });
    await snap(page, SPEC, 'find-offered-on-click');
  });

  test('The ghost tile turns with its rotate button', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      tileSequence: ['threeSide', 'fourSide'],
      playerConfigs: { [a]: {}, [b]: {} },
    });

    const spot = page.locator('.available-place.clickable.place-tile[title^="Available position: 0,-1 "]');
    await spot.click();
    // the orientation is a class of the ghost tile (its symbol is hidden behind the tile art)
    const ghost = page.locator('.tile.ghost-tile').first();
    await expect(ghost).toBeVisible();
    const before = await ghost.getAttribute('class');
    await snap(page, SPEC, 'ghost-tile', { phone: true });
    await rotateGhost(page, 1);
    // the turn goes through the server: wait for the new orientation
    await expect.poll(() => ghost.getAttribute('class'), { timeout: 8000 }).not.toBe(before);
    console.log(`[spec] ghost ${before} → ${await ghost.getAttribute('class')}`);
    await page.keyboard.press('Enter');
    await expect.poll(async () => (await apiGame(request, gameId)).field.tileOrientations['0,-1'], { timeout: 10000 }).toBeTruthy();
    console.log(`[spec] placed as ${(await apiGame(request, gameId)).field.tileOrientations['0,-1']}`);
  });

  test('A fight waiting for a decision survives a reload', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      // 2 + 3 = 5 ties the rat: the spell choice waits
      diceRolls: [2, 3],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_rat', 'giant_rat'],
      playerConfigs: { [a]: { inventory: ['fireball'] }, [b]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    const dice = async () => (await page.locator('.battle-overlay .dice-face').evaluateAll((els) => els.map((el) => el.getAttribute('data-value')))).join(',');
    const rolled = await dice();
    console.log(`[spec] dice ${rolled}, reload`);
    await page.reload();
    await waitForFight(page);
    expect(await dice()).toBe(rolled);
    await snap(page, SPEC, 'fight-restored');
    await strikeWith(page, [await packItemId(request, gameId, a, 'fireball')]);
    await waitForFightClosed(page);
    expect(await packTypes(request, gameId, a)).toEqual(['dagger']);
  });

  test('The dragon ends the game: results, then the Hall of fame', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      // 6 + 6 + axe 3 + sword 2 = 17 beats the dragon (15)
      diceRolls: [6, 6],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['dragon', 'giant_rat'],
      playerConfigs: { [a]: { inventory: ['axe', 'sword', { type: 'chest', treasureValue: 2 }] }, [b]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    // the win over the dragon ends the game at once: the results come up over the fight
    await expect.poll(async () => (await apiGame(request, gameId)).state.status, { timeout: 20000 }).toBe('finished');
    await expect(page.locator('.leaderboard-overlay')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('.leaderboard-overlay .result-banner.result-win')).toBeVisible();
    await expect(page.locator('.leaderboard-overlay .lb-winner')).toBeVisible();
    await snap(page, SPEC, 'results', { phone: true });

    console.log('[spec] back to the board: the finished banner');
    await page.locator('.leaderboard-overlay .lb-btn-secondary').click();
    await expect(page.locator('.game-finished-banner')).toBeVisible({ timeout: 10000 });
    await snap(page, SPEC, 'game-finished', { phone: true });
    await page.locator('.view-results-button').click();
    await expect(page.locator('.leaderboard-overlay')).toBeVisible();

    // test players have no username, so the Hall of fame does not list them: just open it
    await page.goto(`${API_URL}/leaderboard`);
    await expect(page.locator('.leaderboard-table')).toBeVisible({ timeout: 10000 });
    await snap(page, SPEC, 'hall-of-fame-after-win');
  });
});
