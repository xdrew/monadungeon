import { test, expect } from '@playwright/test';
import { v4 as uuidv4 } from 'uuid';
import { GameHelpers } from '../utils/game-helpers';
import { snap } from '../utils/screens';
import {
  API_URL, apiGame, apiPlayer, endTurn, expectNotice, packItemId, packTypes, position, startGame, stopTestMode,
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

  test('Battle Arena with two heroes: both choose their spells before the roll, then the dice, the winner takes loot', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      // A rolls 6 + 6 = 12 (+ the fireball chosen before = 13), B rolls 1 + 1 = 2 (+ her dagger 1)
      diceRolls: [6, 6, 1, 1],
      tileSequence: ['battleArena', 'fourSide'],
      playerConfigs: { [a]: { inventory: ['fireball'] }, [b]: { inventory: ['dagger'] } },
    });

    await GameHelpers.placeTile(page, 0, 0);
    console.log('[spec] one rival: no choice, B is summoned and the duel scene opens on A choosing his spells');
    await expect(page.getByTestId('duel-scene')).toBeVisible({ timeout: 15000 });
    await expect(page.getByTestId('arena-banner')).toHaveCount(0);
    await expect.poll(async () => position(request, gameId, b)).toBe(await position(request, gameId, a));
    await expect(page.getByTestId('duel-prepare')).toBeVisible();
    await expect(page.getByTestId('duel-total-challenger')).toHaveCount(0);
    await page.locator('[data-testid^="duel-spell-"]').first().click();
    await snap(page, SPEC, 'duel-prepare', { phone: true });
    await page.getByTestId('duel-prepare-go').click();

    console.log('[spec] B chooses next (at this screen), seeing what A spends — still no dice');
    await expect(page.getByTestId('duel-prep-challenger')).toContainText('Fireball', { timeout: 10000 });
    await expect(page.getByTestId('duel-prepare')).toBeVisible({ timeout: 10000 });
    await expect(page.getByTestId('duel-total-challenger')).toHaveCount(0);
    await page.getByTestId('duel-prepare-go').click();

    console.log('[spec] the dice of both, the outcome, the loot');
    await expect(page.getByTestId('duel-slam')).toBeVisible({ timeout: 15000 });
    await expect(page.getByTestId('duel-total-challenger')).toHaveText('13');
    await expect(page.getByTestId('duel-total-opponent')).toHaveText('3');
    await snap(page, SPEC, 'duel-result', { phone: true });
    await page.locator('[data-testid^="duel-loot-"]:not([data-testid="duel-loot-choice"])').first().click();
    await page.getByTestId('duel-dismiss').click();
    await expect(page.getByTestId('duel-scene')).toHaveCount(0);
    await expect.poll(async () => packTypes(request, gameId, a), { timeout: 10000 }).toEqual(['dagger']);
  });

  test('Battle Arena with three heroes: the opener chooses the rival and cannot walk on before', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4(); const c = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      diceRolls: [6, 6, 1, 1],
      tileSequence: ['battleArena', 'fourSide'],
      playerConfigs: { [a]: {}, [b]: {}, [c]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await expect(page.getByTestId('arena-banner')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-testid="arena-marker"]').first()).toBeVisible();
    await expect(page.getByTestId(`duel-opponent-${b}`)).toBeVisible();
    await expect(page.getByTestId(`duel-opponent-${c}`)).toBeVisible();
    console.log('[spec] while the rival is not chosen there is nowhere to walk, and the server refuses a move');
    await expect(page.locator('.available-place.clickable:not(.place-tile)')).toHaveCount(0);
    const arena = await position(request, gameId, a);
    const refused = await request.post(`${API_URL}/api/game/move-player`, {
      data: { gameId, playerId: a, turnId: (await apiGame(request, gameId)).state.currentTurnId, fromPosition: arena, toPosition: '0,0' },
    });
    expect(refused.ok()).toBe(false);
    expect(await position(request, gameId, a)).toBe(arena);
    await snap(page, SPEC, 'arena-opponent-choice', { phone: true });

    await page.getByTestId(`duel-opponent-${c}`).click();
    await expect(page.getByTestId('duel-prepare')).toBeVisible({ timeout: 15000 });
    expect(await position(request, gameId, c)).toBe(arena);
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
    console.log('[spec] the Cursed Room scene: the die lands on 2, the token goes onto the hero');
    const scene = page.getByTestId('cursed-room-scene');
    await expect(scene).toBeVisible({ timeout: 10000 });
    await expect(scene).toHaveAttribute('data-roll', '2');
    await expect(page.getByTestId('cursed-room-outcome')).toContainText(/cursed/, { timeout: 6000 });
    await snap(page, SPEC, 'cursed-room-scene', { phone: true });
    await page.getByTestId('cursed-room-ok').click();
    await expect(scene).toHaveCount(0);
    await expectNotice(page, /Cursed Room — .* rolled 2 .*cursed/);
    await expect(page.locator('[data-testid="cursed-room-marker"]').first()).toBeVisible();
    await snap(page, SPEC, 'cursed-room-cursed', { phone: true });
    expect((await apiPlayer(request, gameId, a)).cursed).toBe(true);

    console.log('[spec] three more Lord Karak rooms for A, the fifth for B');
    for (let i = 0; i < 3; i++) await GameHelpers.placeTile(page, 0, 0);
    await expect.poll(async () => (await apiGame(request, gameId)).state.currentPlayerId, { timeout: 10000 }).toBe(b);
    await switchTo(page, b);
    await GameHelpers.placeTile(page, 0, 0);
    console.log('[spec] B rolls 6 in the fifth room: spared, the curse stays in the room');
    await expect(page.getByTestId('cursed-room-outcome')).toContainText(/Spared/, { timeout: 10000 });
    await page.getByTestId('cursed-room-ok').click();

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
