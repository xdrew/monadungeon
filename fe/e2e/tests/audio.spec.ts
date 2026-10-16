import { test, expect, type Page } from '@playwright/test';
import { v4 as uuidv4 } from 'uuid';
import { GameHelpers } from '../utils/game-helpers';
import { snap } from '../utils/screens';
import {
  API_URL, apiGame, END_TURN, startGame, stopTestMode, takeReward, waitForFight, waitForFightClosed, watchPage,
} from '../utils/hotseat';

/**
 * Sound: the sound ring's volumes, music by situation and the cues of the game's actions. Headless
 * Chromium plays nothing audible, so the dev build's cue log (window.__audioCues: {kind, id}) is what
 * is checked: kind 'cue' a sound, 'music' the situation's track, 'sting' a one-shot musical cue.
 */
const SPEC = 'audio';

type Entry = { kind: 'cue' | 'music' | 'sting'; id: string | null };
const log = (page: Page): Promise<Entry[]> => page.evaluate(() => (window as unknown as { __audioCues?: Entry[] }).__audioCues || []);
const ids = async (page: Page, kind: Entry['kind']) => (await log(page)).filter((e) => e.kind === kind).map((e) => e.id);
const clearLog = (page: Page) => page.evaluate(() => { (window as unknown as { __audioCues?: Entry[] }).__audioCues = []; });

/** `wanted` appear in `seen` in this order (other entries may come between). */
const inOrder = (seen: (string | null)[], wanted: string[]) => {
  let i = 0;
  for (const id of seen) if (id === wanted[i]) i++;
  return i === wanted.length;
};

test.describe('Audio', () => {
  test.beforeEach(async ({ page }) => { test.setTimeout(150000); watchPage(page); });
  test.afterEach(async ({ request }) => stopTestMode(request));

  test('the sound ring sets the music and the sounds apart, keeps them, and mutes', async ({ page }) => {
    await page.goto(API_URL);
    await expect.poll(() => ids(page, 'music')).toContain('lobby');
    await page.locator('.music-toggle').first().click();
    const panel = page.getByTestId('sound-panel');
    await expect(panel).toBeVisible();
    await page.getByTestId('sound-music').fill('0.3');
    await page.getByTestId('sound-sfx').fill('0.65');
    await snap(page, SPEC, 'sound-panel', { phone: true });

    await page.reload();
    await page.locator('.music-toggle').first().click();
    await expect(page.getByTestId('sound-music')).toHaveValue('0.3');
    await expect(page.getByTestId('sound-sfx')).toHaveValue('0.65');

    console.log('[spec] mute: the ring goes dark, the sliders rest, the setting survives a reload');
    await page.getByTestId('sound-mute').click();
    await expect(page.locator('.music-toggle').first()).toHaveClass(/music-off/);
    await expect(page.getByTestId('sound-music')).toBeDisabled();
    type Engine = { __audio: { state(): { playing: boolean; settings: { muted: boolean } } } };
    const state = () => page.evaluate(() => (window as unknown as Engine).__audio.state());
    expect((await state()).settings.muted).toBe(true);
    // the music fades out, then stops
    await expect.poll(async () => (await state()).playing, { timeout: 5000 }).toBe(false);
    await page.reload();
    await expect(page.locator('.music-toggle').first()).toHaveClass(/music-off/);
    expect(await page.evaluate(() => localStorage.getItem('audio.muted'))).toBe('true');
    await page.evaluate(() => { localStorage.removeItem('audio.muted'); localStorage.removeItem('audio.music'); localStorage.removeItem('audio.sfx'); });
  });

  test('a turn sounds: the tile, the step, the fight\'s roar, dice and blow, the pickup, the end of the turn', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      diceRolls: [6, 6], // 12 beats the giant rat (5)
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_rat', 'giant_rat'],
      playerConfigs: { [a]: {}, [b]: {} },
    });
    await expect.poll(() => ids(page, 'music')).toContain('explore');
    await clearLog(page);

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await expect.poll(() => ids(page, 'music')).toContain('fight');
    await takeReward(page);
    await waitForFightClosed(page);
    await expect.poll(() => ids(page, 'music'), { timeout: 10000 }).toEqual(['fight', 'explore']);

    const cues = await expect.poll(async () => {
      const seen = await ids(page, 'cue');
      return inOrder(seen, ['tile_place', 'step', 'monster_roar', 'dice_roll', 'hit_win', 'item_pickup', 'end_turn', 'your_turn']);
    }, { timeout: 15000 }).toBe(true).then(() => ids(page, 'cue'));
    console.log(`[spec] cues: ${cues.join(', ')}`);
    expect(await ids(page, 'sting')).toContain('victory');
  });

  test('an AI turn played back on an online table sounds like a human one', async ({ page }) => {
    await page.goto(API_URL);
    await page.getByTestId('name-card-input').fill('Listener');
    await page.getByTestId('name-card-save').click();
    await expect(page.getByTestId('account-line')).toContainText('Listener');
    const created = await page.request.post(`${API_URL}/api/tables`, {
      data: { seats: 2, randomHeroes: true, test: { tileSequence: ['fourSide', 'fourSide', 'fourSide', 'fourSide', 'fourSide', 'fourSide'] } },
    });
    expect(created.status()).toBe(201);
    const { gameId } = await created.json();
    expect((await page.request.post(`${API_URL}/api/tables/${gameId}/ai`)).status()).toBe(201);
    await page.goto(`${API_URL}/game/${gameId}`);
    await expect(page.getByTestId('table-room')).toBeVisible();
    await expect.poll(() => ids(page, 'music')).toContain('lobby');
    await page.getByTestId('table-start').click();
    await expect(page.getByTestId('table-room')).toHaveCount(0, { timeout: 15000 });
    await expect.poll(() => ids(page, 'music')).toContain('explore');

    const ai = (await apiGame(page.request, gameId)).players.find((p) => p.isAi)!;
    if ((await apiGame(page.request, gameId)).state.currentPlayerId !== ai.id) {
      console.log('[spec] my turn first: I pass');
      await expect(page.locator(END_TURN)).toHaveCount(1, { timeout: 15000 });
      await clearLog(page);
      await page.locator(END_TURN).first().click();
    } else {
      await clearLog(page);
    }
    console.log('[spec] the AI plays on the server, this browser plays it back with its sounds');
    await expect.poll(async () => (await apiGame(page.request, gameId)).state.currentPlayerId, { timeout: 30000 }).not.toBe(ai.id);
    await expect.poll(async () => {
      const seen = await ids(page, 'cue');
      return (seen.includes('tile_place') || seen.includes('step')) && inOrder(seen, ['end_turn', 'your_turn']);
    }, { timeout: 30000 }).toBe(true);
    console.log(`[spec] cues: ${(await ids(page, 'cue')).join(', ')}`);
  });

  test('the pages keep the lobby music; the interface clicks', async ({ page }) => {
    await page.goto(`${API_URL}/rules`);
    await expect.poll(() => ids(page, 'music')).toEqual(['lobby']);
    await clearLog(page);
    await page.locator('.music-toggle').first().click(); // the ring itself makes no click
    await page.locator('.music-toggle').first().click();
    expect(await ids(page, 'cue')).not.toContain('ui_click');
    await page.getByRole('button').filter({ hasNotText: /^$/ }).first().click();
    await expect.poll(() => ids(page, 'cue')).toContain('ui_click');
  });
});
