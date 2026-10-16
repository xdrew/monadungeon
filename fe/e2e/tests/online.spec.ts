import { test, expect, type Page } from '@playwright/test';
import { v4 as uuidv4 } from 'uuid';
import { GameHelpers } from '../utils/game-helpers';
import { snap } from '../utils/screens';
import { API_URL, apiGame, startGame, stopTestMode, watchPage } from '../utils/hotseat';

/**
 * Online play, part 1: a guest account with a name, seats that belong to it (the server refuses anyone
 * else acting for them), and the Hall of fame by players and by heroes.
 */
const SPEC = 'online';

test.describe('Online: accounts and the Hall of fame', () => {
  test.beforeEach(async ({ page }) => { test.setTimeout(120000); watchPage(page); });
  test.afterEach(async ({ request }) => stopTestMode(request));

  test('a name on the start screen, kept across a reload, renamed with the pen', async ({ page }) => {
    await page.goto(API_URL);
    await expect(page.getByTestId('name-card')).toBeVisible();
    await expect(page.locator('.primary-button')).toHaveCount(0);
    await snap(page, SPEC, 'name-card', { phone: true });

    await page.getByTestId('name-card-input').fill('Ada');
    await page.getByTestId('name-card-save').click();
    await expect(page.getByTestId('account-line')).toContainText('Ada');
    await expect(page.locator('.primary-button')).toBeEnabled();
    await snap(page, SPEC, 'signed-in', { phone: true });

    console.log('[spec] the cookie keeps the account across a reload');
    await page.reload();
    await expect(page.getByTestId('account-line')).toContainText('Ada');

    await page.getByTestId('account-rename').click();
    await page.getByTestId('account-name-input').fill('Ada the Bold');
    await page.getByTestId('account-save').click();
    await expect(page.getByTestId('account-line')).toContainText('Ada the Bold');

    console.log('[spec] a name that does not fit is refused by the server');
    const bad = await page.request.patch(`${API_URL}/api/account/me`, { data: { name: '<b>x</b>' } });
    expect(bad.status()).toBe(422);
  });

  test('a seat belongs to its account: another browser cannot act for it', async ({ page, browser }) => {
    const owner = page.request;
    expect((await owner.post(`${API_URL}/api/account/guest`, { data: { name: 'Owner' } })).status()).toBe(201);

    const gameId = uuidv4(); const human = uuidv4(); const ai = uuidv4();
    await owner.post(`${API_URL}/api/game`, { data: { gameId } });
    expect((await owner.post(`${API_URL}/api/game/player`, { data: { gameId, playerId: human, username: 'spoofed' } })).ok()).toBe(true);
    await owner.post(`${API_URL}/api/game/player`, { data: { gameId, playerId: ai, username: 'AI Player', isAi: true } });
    for (const playerId of [human, ai]) await owner.post(`${API_URL}/api/game/player/ready`, { data: { gameId, playerId } });
    await owner.post(`${API_URL}/api/game/start`, { data: { gameId } });
    const game = await apiGame(owner, gameId);
    const seat = game.players.find((p) => p.id === human) as unknown as { username?: string };
    expect(seat.username).toBe('Owner');

    const stranger = await browser.newContext();
    try {
      const turnId = game.state.currentTurnId;
      const humanTurn = game.state.currentPlayerId === human;
      console.log(`[spec] the stranger tries to act for the owner's seat (${humanTurn ? 'its turn' : 'the AI is first'})`);
      const theirs = await stranger.request.post(`${API_URL}/api/game/end-turn`, { data: { gameId, playerId: human, turnId } });
      expect(theirs.status()).toBe(403);
      expect((await theirs.json()).code).toBe('seat.not_yours');
      const aiTurn = await stranger.request.post(`${API_URL}/api/game/virtual-player-turn`, { data: { gameId, playerId: ai } });
      expect(aiTurn.status()).toBe(403);
      const joinAnon = await stranger.request.post(`${API_URL}/api/game/player`, { data: { gameId: uuidv4(), playerId: uuidv4() } });
      expect(joinAnon.status()).toBe(401);
      if (humanTurn) {
        expect((await owner.post(`${API_URL}/api/game/end-turn`, { data: { gameId, playerId: human, turnId } })).status()).toBe(200);
      }
    } finally {
      await stranger.close();
    }
  });

  test('keep the account with Google, sign in with it on another device, sign out', async ({ page, browser }) => {
    // the dev/test fake provider signs in as localStorage.googleFakeAs ("sub:email")
    const sub = `e2e-${Date.now()}`;
    const asGoogle = async (p: Page, who: string) => p.addInitScript((v) => localStorage.setItem('googleFakeAs', v), who);
    await asGoogle(page, `${sub}:ada@gmail.com`);
    await page.goto(API_URL);
    await page.getByTestId('name-card-input').fill('Ada');
    await page.getByTestId('name-card-save').click();
    await expect(page.getByTestId('account-keep-google')).toBeVisible();

    console.log('[spec] a guest keeps the account with Google');
    await page.getByTestId('account-keep-google').click();
    await expect(page.getByTestId('account-notice')).toContainText('kept with Google');
    await expect(page.getByTestId('account-google')).toContainText('a…@gmail.com');
    await expect(page).not.toHaveURL(/google=/);
    await snap(page, SPEC, 'kept-with-google', { phone: true });

    console.log('[spec] another device signs in with the same Google account and is Ada');
    const other = await (await browser.newContext()).newPage();
    watchPage(other);
    await asGoogle(other, `${sub}:ada@gmail.com`);
    await other.goto(API_URL);
    await expect(other.getByTestId('name-card-google')).toBeVisible();
    await snap(other, SPEC, 'sign-in-with-google', { phone: true });
    await other.getByTestId('name-card-google').click();
    await expect(other.getByTestId('account-line')).toContainText('Ada');
    await expect(other.getByTestId('account-notice')).toContainText('Welcome back, Ada');
    const meA = await (await page.request.get(`${API_URL}/api/account/me`)).json();
    const meB = await (await other.request.get(`${API_URL}/api/account/me`)).json();
    expect(meB.id).toBe(meA.id);

    console.log('[spec] that device signs out; the first stays signed in');
    await other.getByTestId('account-sign-out').click();
    await expect(other.getByTestId('name-card')).toBeVisible();
    await expect(other.getByTestId('account-notice')).toContainText('Signed out');
    expect((await other.request.get(`${API_URL}/api/account/me`)).status()).toBe(204);
    await page.reload();
    await expect(page.getByTestId('account-line')).toContainText('Ada');

    console.log('[spec] a Google account nobody keeps makes a new account on a new device');
    const third = await (await browser.newContext()).newPage();
    watchPage(third);
    await asGoogle(third, `${sub}-c:carol@example.com`);
    await third.goto(API_URL);
    await third.getByTestId('name-card-google').click();
    await expect(third.getByTestId('account-line')).toContainText('Carol');
    await expect(third.getByTestId('account-notice')).toContainText('A new account, Carol');

    console.log('[spec] a guest cannot sign out (the account would be lost)');
    const guestOut = await other.request.post(`${API_URL}/api/account/guest`, { data: { name: 'Plain' } });
    expect(guestOut.status()).toBe(201);
    expect((await other.request.post(`${API_URL}/api/account/sign-out`)).status()).toBe(409);
    await other.context().close();
    await third.context().close();
  });

  test('an invite link signs in with Google and takes the seat', async ({ page, browser }) => {
    const host = page.request;
    expect((await host.post(`${API_URL}/api/account/guest`, { data: { name: 'Hostess' } })).status()).toBe(201);
    const { gameId } = await (await host.post(`${API_URL}/api/tables`, { data: { seats: 2 } })).json();
    const sub = `e2e-inv-${Date.now()}`;
    // the guest is kept with Google on one device first
    const before = await (await browser.newContext()).newPage();
    await before.request.post(`${API_URL}/api/account/guest`, { data: { name: 'Roamer' } });
    await before.goto(`${API_URL}/api/account/google/start?return=/&as=${sub}:roamer@example.com`);
    await before.context().close();

    const phone = await (await browser.newContext()).newPage();
    watchPage(phone);
    await phone.addInitScript((v) => localStorage.setItem('googleFakeAs', v), `${sub}:roamer@example.com`);
    await phone.goto(`${API_URL}/table/${gameId}`);
    await phone.getByTestId('name-card-google').click();
    await expect(phone).toHaveURL(new RegExp(`/game/${gameId}`), { timeout: 15000 });
    await expect(phone.getByTestId('table-room')).toContainText('Roamer');
    await phone.context().close();
  });

  test('a won game shows in the Hall of fame, by player and by hero', async ({ page }) => {
    const me = page.request;
    const created = await me.post(`${API_URL}/api/account/guest`, { data: { name: 'Dragonslayer' } });
    const accountId = (await created.json()).id as string;
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, me, {
      gameId,
      // 6 + 6 + axe 3 + sword 2 = 17 beats the dragon (15)
      diceRolls: [6, 6],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['dragon', 'giant_rat'],
      playerConfigs: { [a]: { hero: 'warrior', accountId, inventory: ['axe', 'sword'] }, [b]: { hero: 'thief' } },
    });
    await GameHelpers.placeTile(page, 0, 0);
    await expect.poll(async () => (await apiGame(me, gameId)).state.status, { timeout: 20000 }).toBe('finished');

    await page.goto(`${API_URL}/leaderboard`);
    const mine = page.locator('.leaderboard-table tbody tr.is-you');
    await expect(mine).toContainText('Dragonslayer', { timeout: 10000 });
    await expect(mine.locator('.you-mark')).toBeVisible();
    await snap(page, SPEC, 'hall-players', { phone: true });

    await page.getByTestId('leaderboard-tab-heroes').click();
    await expect(page).toHaveURL(/tab=heroes/);
    const heroes = page.getByTestId('heroes-table');
    await expect(heroes.locator('tbody tr', { hasText: 'Horan' })).toBeVisible();
    await snap(page, SPEC, 'hall-heroes', { phone: true });
  });
});
