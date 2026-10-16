import { test, expect } from '@playwright/test';
import { v4 as uuidv4 } from 'uuid';
import { GameHelpers } from '../utils/game-helpers';
import { snap } from '../utils/screens';
import {
  apiGame, apiPlayer, endTurn, expectNotice, packItemId, packTypes, position, startGame, stopTestMode,
  strikeWith, switchTo, takeReward, waitForFight, waitForFightClosed, watchPage,
} from '../utils/hotseat';

/**
 * Karak: Regent mechanics (regent: true): the Battle Arena duel, Cursed Rooms, the Giant Bat's Dark Thorn,
 * the Skeleton Mage's Frost Fist and the Lord Karak variant.
 * Fight arithmetic: 2 dice + weapons + bonuses; more than the monster's strength wins, equal is a draw.
 */
const SPEC = 'regent-mechanics';

test.describe('Regent mechanics', () => {
  test.beforeEach(async ({ page }) => { test.setTimeout(180000); watchPage(page); });
  test.afterEach(async ({ request }) => stopTestMode(request));

  test('Giant Bat drops a Dark Thorn, cast at the rival for 1 HP', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      // 4 + 4 = 8 beats the bat (6)
      diceRolls: [4, 4],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_bat', 'giant_rat'],
      playerConfigs: { [a]: {}, [b]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await snap(page, SPEC, 'giant-bat-win', { phone: true });
    await takeReward(page);
    await waitForFightClosed(page);
    expect(await packTypes(request, gameId, a)).toEqual(['dark_thorn']);

    await switchTo(page, b);
    await endTurn(page);
    await switchTo(page, a);
    console.log('[spec] cast the Dark Thorn at B');
    const thorn = await packItemId(request, gameId, a, 'dark_thorn');
    await page.getByTestId(`dark-thorn-${thorn}`).click();
    await expect(page.getByTestId('dark-thorn-banner')).toBeVisible();
    await page.getByTestId(`dark-thorn-target-${b}`).click();
    await snap(page, SPEC, 'dark-thorn-target', { phone: true });
    await page.getByTestId('dark-thorn-confirm').click();
    await expect.poll(async () => (await apiPlayer(request, gameId, b)).hp, { timeout: 10000 }).toBe(4);
    expect(await packTypes(request, gameId, a)).toEqual([]);
  });

  test('Skeleton Mage: a Frost Fist (+2) turns the loss into a win', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      // 5 + 5 = 10 loses to the mage (11); the Frost Fist +2 → 12 wins
      diceRolls: [5, 5],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['skeleton_mage', 'giant_rat'],
      playerConfigs: { [a]: { inventory: ['frost_fist'] }, [b]: {} },
    });
    const fist = await packItemId(request, gameId, a, 'frost_fist');

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await page.locator(`.consumable-row .item-chip.selectable[data-item-id="${fist}"]`).click();
    await snap(page, SPEC, 'frost-fist-picked', { phone: true });
    await strikeWith(page, []);
    await waitForFightClosed(page);
    // the spent fist is gone, the mage's own Frost Fist is the reward
    const pack = (await apiPlayer(request, gameId, a)).inventory.spells || [];
    expect(pack.map((item) => item.type)).toEqual(['frost_fist']);
    expect(pack[0].itemId).not.toBe(fist);
  });

  test('Battle Arena: a duel with the rival, the winner takes loot', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      // A rolls 6 + 6 = 12, B rolls 1 + 1 = 2 (+ her dagger 1)
      diceRolls: [6, 6, 1, 1],
      tileSequence: ['battleArena', 'fourSide'],
      playerConfigs: { [a]: {}, [b]: { inventory: ['dagger'] } },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await expect(page.getByTestId('arena-banner')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-testid="arena-marker"]').first()).toBeVisible();
    await snap(page, SPEC, 'arena-opponent-choice', { phone: true });
    await page.getByTestId(`duel-opponent-${b}`).click();

    console.log('[spec] the duel: A rolls, then the summoned rival rolls in this browser');
    for (let side = 0; side < 3; side++) {
      const overlay = page.locator('.battle-overlay');
      // isVisible() does not wait: wait for the next side's scene, or stop when the duel is settled
      const next = await Promise.race([
        overlay.waitFor({ state: 'visible', timeout: 10000 }).then(() => 'fight'),
        page.getByTestId('duel-result').waitFor({ state: 'visible', timeout: 10000 }).then(() => 'result'),
      ]).catch(() => 'none');
      if (next !== 'fight') break;
      await page.locator('.battle-overlay .battle-footer.footer-reveal').first().waitFor({ state: 'visible', timeout: 20000 });
      await snap(page, SPEC, `duel-side-${side + 1}`, { phone: side === 0 });
      await page.locator('.battle-overlay [data-testid="finalize-battle"], .battle-overlay [data-testid="duel-continue"]').first().click();
      await page.waitForTimeout(1200);
    }

    await expect(page.getByTestId('duel-result')).toBeVisible({ timeout: 15000 });
    await snap(page, SPEC, 'duel-result', { phone: true });
    const loot = page.locator('[data-testid^="duel-loot-"]:not([data-testid="duel-loot-choice"])').first();
    if (await loot.isVisible().catch(() => false)) await loot.click();
    await page.getByTestId('duel-dismiss').click();
    await expect.poll(async () => packTypes(request, gameId, a), { timeout: 10000 }).toEqual(['dagger']);
  });

  test('Cursed Room curses on a low roll; the fifth Lord Karak room reveals Lord Karak', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      lordKarak: true,
      // A enters a cursed room: 2 → cursed (a cursed hero does not roll again); B enters the fifth: 6 → safe
      diceRolls: [2, 6, 6, 6, 6],
      tileSequence: ['cursedRoom', 'cursedRoom', 'cursedRoom', 'cursedRoom', 'cursedRoom', 'fourSide'],
      // B carries a dagger: A is the weakest hero and becomes Lord Karak
      playerConfigs: { [a]: { hero: 'warrior' }, [b]: { hero: 'thief', inventory: ['dagger'] } },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await expectNotice(page, /Cursed Room — .* rolled 2: cursed!/);
    await expect(page.locator('[data-testid="cursed-room-marker"]').first()).toBeVisible();
    await snap(page, SPEC, 'cursed-room-cursed', { phone: true });
    expect((await apiPlayer(request, gameId, a)).cursed).toBe(true);

    console.log('[spec] three more Lord Karak rooms for A, the fifth for B');
    for (let i = 0; i < 3; i++) await GameHelpers.placeTile(page, 0, 0);
    await expect.poll(async () => (await apiGame(request, gameId)).state.currentPlayerId, { timeout: 10000 }).toBe(b);
    await switchTo(page, b);
    await GameHelpers.placeTile(page, 0, 0);

    await expect(page.getByTestId('lord-karak-modal')).toBeVisible({ timeout: 15000 });
    await expect(page.getByTestId('lord-karak-who')).toContainText(/Horan becomes Lord Karak/);
    await snap(page, SPEC, 'lord-karak-reveal', { phone: true });
    await page.getByTestId('lord-karak-close').click();
    const lord = await apiPlayer(request, gameId, a);
    expect(lord.lordKarak).toBe(true);
    expect(lord.cursed).toBe(false);
    await expect(page.locator('[data-testid="hero-badge"].lord-karak').first()).toBeVisible();
    await snap(page, SPEC, 'lord-karak-rail');
    console.log(`[spec] B is at ${await position(request, gameId, b)}`);
  });
});
