import { APIRequestContext, Page, expect } from '@playwright/test';
import { GameHelpers } from './game-helpers';
import { TestGameConfig, TestGameSetup } from './test-game-setup';

/**
 * Shared pieces of the topic specs (heroes, mechanics): two humans in one browser, switching sessions.
 *
 * Note: GameHelpers.placeTile ignores its x/y and takes the first free placement, and placing a tile
 * moves the hero onto it; sequences are planned around that.
 */
export const API_URL = process.env.API_URL || 'http://sf.sf:18080';

export interface ApiPlayer {
  id: string;
  hp: number;
  maxHp: number;
  hero: string | null;
  cursed: boolean;
  inventory: Record<string, { itemId: string; type: string; name: string; treasureValue: number }[]>;
  heroFlags: Record<string, boolean> | unknown[];
  awaitingReincarnation?: boolean;
  lordKarak?: boolean;
}

export interface ApiGame {
  state: Record<string, any>;
  players: ApiPlayer[];
  field: Record<string, any>;
  turns: Record<string, any>[];
}

/** Errors in the page and 5xx answers go to the test log. */
export function watchPage(page: Page): void {
  page.on('console', (msg) => {
    // the music cannot autoplay in a headless browser: not an error of the game
    if (msg.type() === 'error' && !msg.text().includes('Failed to play music')) console.log(`[browser error] ${msg.text()}`);
  });
  page.on('response', (response) => {
    if (response.status() >= 500) {
      console.log(`[http ${response.status()}] ${response.url()}`);
      response.text().then((body) => console.log(`[http body] ${body.substring(0, 400)}`)).catch(() => {});
    }
  });
}

export async function apiGame(request: APIRequestContext, gameId: string): Promise<ApiGame> {
  const response = await request.get(`${API_URL}/api/game/${gameId}`);
  return response.json();
}

export async function apiPlayer(request: APIRequestContext, gameId: string, playerId: string): Promise<ApiPlayer> {
  const game = await apiGame(request, gameId);
  const player = game.players.find((p) => p.id === playerId);
  if (!player) throw new Error(`player ${playerId} not in game ${gameId}`);
  return player;
}

export async function position(request: APIRequestContext, gameId: string, playerId: string): Promise<string> {
  return (await apiGame(request, gameId)).field.playerPositions[playerId];
}

export async function readyAll(request: APIRequestContext, gameId: string, playerIds: string[]): Promise<void> {
  for (const playerId of playerIds) {
    await request.post(`${API_URL}/api/game/player/ready`, { data: { playerId, gameId } });
  }
}

/** Open the game as this player (first time: lobby origin for localStorage, then the game). */
export async function openGameAs(page: Page, gameId: string, playerId: string): Promise<void> {
  await page.goto(API_URL);
  await GameHelpers.setupPlayerSession(page, playerId);
  await page.goto(`${API_URL}/game/${gameId}`);
  await GameHelpers.waitForGameLoad(page).catch(() => {});
  await page.waitForFunction(() => document.querySelector('h3')?.textContent?.includes('Turn:'), null, { timeout: 15000 });
}

/** Hand the browser to the other player (hotseat). */
export async function switchTo(page: Page, playerId: string): Promise<void> {
  await GameHelpers.setupPlayerSession(page, playerId);
  await page.reload();
  await page.waitForFunction(() => document.querySelector('h3')?.textContent?.includes('Turn:'), null, { timeout: 15000 });
  await page.waitForTimeout(1200);
}

export async function turnNumber(page: Page): Promise<number> {
  const text = (await page.locator('h3:has-text("Turn:")').first().textContent()) || '';
  return parseInt(text.replace(/[^0-9]/g, ''), 10);
}

export async function waitForTurn(page: Page, n: number): Promise<void> {
  await expect(page.locator('h3:has-text("Turn:")').first()).toHaveText(new RegExp(`Turn:\\s*${n}\\b`), { timeout: 15000 });
}

/** The fight scene: open, and (optionally) the dice settled with the result shown. */
export async function waitForFight(page: Page): Promise<void> {
  await page.locator('.battle-overlay').waitFor({ state: 'visible', timeout: 15000 });
  // the footer is revealed once the dice have settled and the result is shown
  await page.locator('.battle-overlay .battle-footer.footer-reveal').waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForTimeout(400);
}

/** The won fight: take the reward (the ember button of the victory footer). */
export async function takeReward(page: Page): Promise<void> {
  await page.locator('.battle-overlay .battle-footer .btn-primary').first().click();
}

/** After a roll that does not win: pick spells by item id and/or Sacrifice, then strike. */
export async function strikeWith(page: Page, itemIds: string[], sacrifice = false): Promise<void> {
  for (const id of itemIds) {
    await page.locator(`.consumable-row .item-chip.selectable[data-item-id="${id}"]`).click();
  }
  if (sacrifice) await page.getByTestId('sacrifice-hp').click();
  await page.locator('.battle-overlay .btn-strike').click();
}

export async function waitForFightClosed(page: Page): Promise<void> {
  await page.locator('.battle-overlay').waitFor({ state: 'hidden', timeout: 15000 });
  await page.waitForTimeout(500);
}

/** A HUD ring or field control by its data-testid. */
export async function clickTestId(page: Page, testId: string): Promise<void> {
  const el = page.locator(`[data-testid="${testId}"]`).first();
  await el.waitFor({ state: 'visible', timeout: 10000 });
  await el.click();
}

/** Rotate the ghost tile n times with its rotate button. */
export async function rotateGhost(page: Page, times: number): Promise<void> {
  for (let i = 0; i < times; i++) {
    await page.locator('.ghost-rotate-btn').first().click();
    await page.waitForTimeout(250);
  }
}

/** The transient notice at the top of the field. */
export async function expectNotice(page: Page, text: RegExp): Promise<void> {
  await expect(page.locator('[data-testid="action-notice"]').first()).toHaveText(text, { timeout: 8000 });
}

/** The End Turn ring (skill rings and dev debug buttons share .end-turn-btn). */
export const END_TURN = '.end-turn-btn:not(.magic-swap-btn):not(.debug-plaque-btn)';

/** End the turn with the HUD ring. */
export async function endTurn(page: Page): Promise<void> {
  const before = await turnNumber(page);
  await page.locator(END_TURN).first().click();
  await expect.poll(() => turnNumber(page), { timeout: 15000 }).toBeGreaterThan(before);
}

/**
 * Test mode on, the game set up and opened as the first player, everyone ready, turn 1 on screen.
 */
export async function startGame(
  page: Page,
  request: APIRequestContext,
  config: TestGameConfig & { gameId: string; playerConfigs: NonNullable<TestGameConfig['playerConfigs']> },
): Promise<void> {
  const setup = new TestGameSetup(API_URL);
  await setup.enableTestMode(request);
  await setup.setupGame(request, config);
  const ids = Object.keys(config.playerConfigs);
  console.log(`[spec] game ${config.gameId}, players ${ids.join(', ')}`);
  await openGameAs(page, config.gameId, ids[0]);
  await readyAll(request, config.gameId, ids);
  await page.waitForTimeout(800);
}

export async function stopTestMode(request: APIRequestContext): Promise<void> {
  await new TestGameSetup(API_URL).disableTestMode(request);
}

/** Item id in a player's pack by type (the n-th of that type). */
export async function packItemId(request: APIRequestContext, gameId: string, playerId: string, type: string, n = 0): Promise<string> {
  const player = await apiPlayer(request, gameId, playerId);
  const all = Object.values(player.inventory).flat().filter((item) => item.type === type);
  if (!all[n]) throw new Error(`no ${type} #${n} in the pack of ${playerId}`);
  return all[n].itemId;
}

export async function packTypes(request: APIRequestContext, gameId: string, playerId: string): Promise<string[]> {
  const player = await apiPlayer(request, gameId, playerId);
  return Object.values(player.inventory).flat().map((item) => item.type).sort();
}

const marker = (x: number, y: number, place: boolean) =>
  `.available-place.clickable${place ? '.place-tile' : ':not(.place-tile)'}[title^="Available position: ${x},${y} "]`;

/** Place the next tile of the deck on this free spot (default orientation) and step onto it. */
export async function placeAt(page: Page, x: number, y: number, opts: { rotate?: number } = {}): Promise<void> {
  const spot = page.locator(marker(x, y, true)).first();
  await spot.waitFor({ state: 'visible', timeout: 15000 });
  await spot.click();
  await page.locator('.tile.ghost-tile, .ghost-rotate-btn').first().waitFor({ state: 'visible', timeout: 10000 });
  if (opts.rotate) await rotateGhost(page, opts.rotate);
  await page.keyboard.press('Enter');
  await page.locator('.tile.ghost-tile').first().waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(600);
  console.log(`[spec] placed a tile at ${x},${y}`);
}

/** Step onto a discovered tile next to the hero. */
export async function moveTo(page: Page, x: number, y: number): Promise<void> {
  const spot = page.locator(marker(x, y, false)).first();
  await spot.waitFor({ state: 'visible', timeout: 15000 });
  await spot.click();
  await page.waitForTimeout(700);
  console.log(`[spec] moved to ${x},${y}`);
}

export const hasMoveMarker = (page: Page, x: number, y: number) => page.locator(marker(x, y, false));
