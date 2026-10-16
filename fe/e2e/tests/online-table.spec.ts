import { test, expect, type Browser, type Page } from '@playwright/test';
import { snap } from '../utils/screens';
import { API_URL, apiGame, END_TURN, placeAt, stopTestMode, turnNumber, watchPage } from '../utils/hotseat';

/**
 * Online play, part 2: tables. Two (or three) browsers, each with its own guest account, sit at one table:
 * the open-table list and the waiting room follow the server live, the host seats the AI and starts,
 * every browser sees the others' moves without a reload, and the server plays the AI seats.
 */
const SPEC = 'online-table';

/** A browser of its own with a named guest account, on the start screen. */
async function guest(page: Page, name: string): Promise<void> {
  watchPage(page);
  await page.goto(API_URL);
  await page.getByTestId('name-card-input').fill(name);
  await page.getByTestId('name-card-save').click();
  await expect(page.getByTestId('account-line')).toContainText(name);
}

async function newGuest(browser: Browser, name: string): Promise<Page> {
  const page = await (await browser.newContext()).newPage();
  await guest(page, name);
  return page;
}

const seat = (page: Page, name: string) => page.getByTestId('table-seat').filter({ hasText: name });
const fieldTiles = (page: Page) => page.locator('.tile:not(.ghost-tile)');

test.describe('Online: tables', () => {
  test.beforeEach(() => { test.setTimeout(180000); });
  test.afterEach(async ({ request }) => stopTestMode(request));

  test('two browsers: the list, the waiting room, heroes, the start and each other\'s moves live', async ({ page, browser }) => {
    await guest(page, 'Ada');
    const bea = await newGuest(browser, 'Bea');

    console.log('[spec] Ada opens a two-seat table (the deck scripted: corridors, no fights)');
    const created = await page.request.post(`${API_URL}/api/tables`, {
      data: { seats: 2, test: { tileSequence: ['fourSide', 'fourSide', 'fourSide', 'fourSide'], itemSequence: ['giant_rat'] } },
    });
    expect(created.status()).toBe(201);
    const { gameId } = await created.json();
    await page.goto(`${API_URL}/game/${gameId}`);
    await expect(page.getByTestId('table-room')).toBeVisible();
    await expect(page.locator('[data-testid="table-seat"][data-free="true"]')).toHaveCount(1);

    console.log('[spec] Bea sees the table in the list without a reload and joins it');
    const row = bea.locator(`[data-testid="open-table-row"][data-game-id="${gameId}"]`);
    await expect(row).toContainText('Ada', { timeout: 10000 });
    await expect(row).toContainText('1/2');
    await snap(bea, SPEC, 'open-tables', { phone: true });
    await row.getByTestId('open-table-join').click();
    await expect(bea).toHaveURL(new RegExp(`/game/${gameId}`));
    await expect(bea.getByTestId('table-room')).toBeVisible();
    await expect(bea.getByTestId('table-waiting')).toContainText('Ada');

    console.log('[spec] Ada\'s waiting room shows Bea at once');
    await expect(seat(page, 'Bea')).toBeVisible({ timeout: 10000 });
    await expect(page.getByTestId('table-start')).toBeDisabled();

    console.log('[spec] heroes: one per table');
    await page.getByTestId('table-hero-warrior').click();
    await expect(seat(page, 'Ada')).toContainText('Horan');
    await expect(bea.getByTestId('table-hero-warrior')).toBeDisabled({ timeout: 10000 });
    await bea.getByTestId('table-hero-thief').click();
    await expect(page.getByTestId('table-start')).toBeEnabled({ timeout: 10000 });
    await snap(page, SPEC, 'waiting-room', { phone: true });

    console.log('[spec] Ada starts; both browsers leave the waiting room');
    await page.getByTestId('table-start').click();
    for (const p of [page, bea]) {
      await expect(p.getByTestId('table-room')).toHaveCount(0, { timeout: 15000 });
      await expect(p.locator('h3').first()).toContainText('Turn: 1');
    }
    // the starting player is rolled for: "first" is whoever starts, "second" the other browser
    const game = await apiGame(page.request, gameId);
    const nameOf = (playerId: string) => (game.players.find((p) => p.id === playerId) as unknown as { username?: string })?.username;
    const firstIsAda = nameOf(game.state.currentPlayerId) === 'Ada';
    const [first, second] = firstIsAda ? [page, bea] : [bea, page];
    const firstSeat = game.state.currentPlayerId;
    console.log(`[spec] ${firstIsAda ? 'Ada' : 'Bea'} starts`);
    await expect(first.locator(END_TURN)).toHaveCount(1);
    await expect(second.locator(END_TURN)).toHaveCount(0);

    console.log('[spec] the first lays a corridor: the other browser sees the tile and the chronicle line without a reload');
    const tilesBefore = await fieldTiles(second).count();
    await placeAt(first, 1, 0);
    await expect.poll(() => fieldTiles(second).count(), { timeout: 10000 }).toBeGreaterThan(tilesBefore);
    await expect(second.locator('.game-hud')).toContainText('finds a new corridor', { timeout: 10000 });
    await snap(second, SPEC, 'opponent-move', { phone: true });

    console.log('[spec] the first ends the turn; the second\'s browser knows it is its turn');
    await first.locator(END_TURN).first().click();
    await expect.poll(() => turnNumber(second), { timeout: 15000 }).toBe(2);
    await expect(second.locator(END_TURN)).toHaveCount(1, { timeout: 10000 });
    await expect(first.locator(END_TURN)).toHaveCount(0);

    console.log('[spec] the second lays a corridor and ends the turn; the first sees both');
    const firstTiles = await fieldTiles(first).count();
    await placeAt(second, -1, 0);
    await expect.poll(() => fieldTiles(first).count(), { timeout: 10000 }).toBeGreaterThan(firstTiles);
    await second.locator(END_TURN).first().click();
    await expect.poll(() => turnNumber(first), { timeout: 15000 }).toBe(3);
    await expect(first.locator(END_TURN)).toHaveCount(1, { timeout: 10000 });

    console.log('[spec] the other browser cannot act for the first one\'s seat');
    const state = (await apiGame(second.request, gameId)).state;
    const refused = await second.request.post(`${API_URL}/api/game/end-turn`, { data: { gameId, playerId: firstSeat, turnId: state.currentTurnId } });
    expect(refused.status()).toBe(403);
    await bea.context().close();
  });

  test('the host seats the AI; the server plays its turn and every browser plays it back', async ({ page, browser }) => {
    await guest(page, 'Host');
    console.log('[spec] the host opens a three-seat table from the start screen (random heroes)');
    const random = page.getByTestId('random-heroes-toggle-input');
    if (!(await random.isChecked())) await random.check();
    await page.getByTestId('open-table').click();
    await page.getByTestId('table-seats-3').click();
    await snap(page, SPEC, 'table-form', { phone: true });
    await page.getByTestId('table-create').click();
    await expect(page).toHaveURL(/\/game\//);
    const gameId = page.url().split('/game/')[1];
    await expect(page.getByTestId('table-room')).toBeVisible();

    console.log('[spec] the host seats the AI, a friend comes by the invite link');
    await page.getByTestId('table-seat-ai').click();
    await expect(seat(page, 'AI')).toBeVisible();
    const invite = await page.getByTestId('table-invite-url').inputValue();
    expect(invite).toContain(`/table/${gameId}`);
    const friend = await (await browser.newContext()).newPage();
    watchPage(friend);
    await friend.goto(invite.replace(/^https?:\/\/[^/]+/, API_URL));
    await expect(friend.getByTestId('name-card')).toBeVisible();
    await snap(friend, SPEC, 'invite-name', { phone: true });
    await friend.getByTestId('name-card-input').fill('Friend');
    await friend.getByTestId('name-card-save').click();
    await expect(friend).toHaveURL(new RegExp(`/game/${gameId}`));
    await expect(friend.getByTestId('table-room')).toBeVisible();
    await expect(seat(page, 'Friend')).toBeVisible({ timeout: 10000 });
    await expect(page.getByTestId('table-seat-ai')).toBeDisabled();

    await page.getByTestId('table-start').click();
    for (const p of [page, friend]) await expect(p.getByTestId('table-room')).toHaveCount(0, { timeout: 15000 });

    // the starting player is rolled for: whoever's turn it is ends it until the AI's comes
    const order = (await apiGame(page.request, gameId)).players;
    const aiSeat = order.find((p) => p.isAi)!;
    const pageOf = (playerId: string) => {
      const who = order.find((p) => p.id === playerId) as unknown as { username?: string };
      return who?.username === 'Friend' ? friend : page;
    };
    // seats take turns in seating order: pass until the AI's seat is next
    const nextSeat = (playerId: string) => order[(order.findIndex((p) => p.id === playerId) + 1) % order.length].id;
    let sawPlayback = false;
    for (let i = 0; i < 3 && !sawPlayback; i++) {
      const { currentPlayerId, turn } = (await apiGame(page.request, gameId)).state;
      const actor = pageOf(currentPlayerId);
      console.log(`[spec] turn ${turn}: ${actor === friend ? 'the friend' : 'the host'} passes`);
      await expect(actor.locator(END_TURN)).toHaveCount(1, { timeout: 15000 });
      await actor.locator(END_TURN).first().click();
      if (nextSeat(currentPlayerId) === aiSeat.id) {
        console.log('[spec] the AI\'s turn: the server plays it, both browsers play it back');
        await Promise.all([page, friend].map((p) => expect(p.locator('.ai-playback')).toBeVisible({ timeout: 15000 })));
        await snap(friend, SPEC, 'ai-playback');
        sawPlayback = true;
      } else {
        await expect.poll(async () => (await apiGame(page.request, gameId)).state.turn, { timeout: 15000 }).toBeGreaterThan(turn);
      }
    }
    expect(sawPlayback).toBe(true);
    console.log('[spec] after the AI the turn comes back to a human, on both screens');
    await expect.poll(async () => {
      const s = (await apiGame(page.request, gameId)).state;
      return s.currentPlayerId !== aiSeat.id;
    }, { timeout: 30000 }).toBe(true);
    const turn = (await apiGame(page.request, gameId)).state.turn;
    for (const p of [page, friend]) await expect.poll(() => turnNumber(p), { timeout: 30000 }).toBe(turn);
    await friend.context().close();
  });

  test('an invite to a full table, and to a table its host closed', async ({ page, browser }) => {
    await guest(page, 'Keeper');
    const created = await page.request.post(`${API_URL}/api/tables`, { data: { seats: 2 } });
    const { gameId } = await created.json();
    expect((await page.request.post(`${API_URL}/api/tables/${gameId}/ai`)).status()).toBe(201);

    const late = await newGuest(browser, 'Late');
    await late.goto(`${API_URL}/table/${gameId}`);
    await expect(late.getByTestId('table-refused')).toHaveAttribute('data-reason', 'table.full');
    await expect(late.getByTestId('table-refused')).toContainText('This table is full');
    await snap(late, SPEC, 'table-full', { phone: true });

    console.log('[spec] the host closes another table; its waiting room and its link say so');
    const other = await (await page.request.post(`${API_URL}/api/tables`, { data: { seats: 3 } })).json();
    await late.goto(`${API_URL}/table/${other.gameId}`);
    await expect(late.getByTestId('table-room')).toBeVisible();
    await page.goto(`${API_URL}/game/${other.gameId}`);
    await page.getByTestId('table-leave').click();
    await expect(page).toHaveURL(`${API_URL}/`);
    await expect(late.getByTestId('table-closed')).toBeVisible({ timeout: 10000 });
    await snap(late, SPEC, 'table-closed');
    const fresh = await newGuest(browser, 'Fresh');
    await fresh.goto(`${API_URL}/table/${other.gameId}`);
    await expect(fresh.getByTestId('table-refused')).toHaveAttribute('data-reason', 'table.closed');
    await late.context().close();
    await fresh.context().close();
  });
});
