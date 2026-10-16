import { test, expect, Page } from '@playwright/test';
import { GameHelpers } from '../utils/game-helpers';
import { snap } from '../utils/screens';
import { API_URL, END_TURN, visibleWithin, waitForFight, watchPage } from '../utils/hotseat';

/**
 * The pages around the game (lobby, language, rules book, Hall of fame, 404) and a real game against
 * the AI from the lobby (no test mode: the AI plays its own turn and the playback bar shows it).
 */
const SPEC = 'pages';

test.describe('Pages', () => {
  test.beforeEach(async ({ page }) => {
    watchPage(page);
    await page.goto(API_URL);
    await page.evaluate(() => {
      for (const key of ['randomHeroes', 'regentExpansion', 'lordKarakVariant', 'selectedHero', 'locale']) localStorage.removeItem(key);
    });
    await page.reload();
  });

  test('lobby options and the hero roster', async ({ page }) => {
    const random = page.getByTestId('random-heroes-toggle-input');
    const regent = page.getByTestId('regent-toggle-input');
    const lordKarak = page.getByTestId('lord-karak-toggle-input');

    console.log('[spec] defaults: random heroes on, Regent off, Lord Karak disabled');
    await expect(random).toBeChecked();
    await expect(regent).not.toBeChecked();
    await expect(lordKarak).toBeDisabled();
    await expect(page.getByTestId('hero-card-random')).toBeVisible();
    await snap(page, SPEC, 'lobby-random', { phone: true });

    console.log('[spec] random off: the base roster, no Regent heroes');
    await page.getByTestId('random-heroes-toggle').click();
    await expect(random).not.toBeChecked();
    await expect(page.getByTestId('hero-warrior')).toBeVisible();
    await expect(page.getByTestId('hero-barbarian')).toHaveCount(0);
    await page.getByTestId('hero-wizard').click();
    await expect(page.getByTestId('hero-wizard')).toHaveAttribute('aria-checked', 'true');
    await expect(page.getByTestId('hero-card')).toBeVisible();
    await snap(page, SPEC, 'lobby-roster', { phone: true });

    console.log('[spec] Regent on: the Regent heroes join, Lord Karak can be switched on');
    await page.getByTestId('regent-toggle').click();
    await expect(regent).toBeChecked();
    await expect(page.getByTestId('hero-barbarian')).toBeVisible();
    await expect(page.getByTestId('hero-ranger')).toBeVisible();
    await expect(lordKarak).toBeEnabled();
    await page.getByTestId('lord-karak-toggle').click();
    await expect(lordKarak).toBeChecked();
    await snap(page, SPEC, 'lobby-regent', { phone: true });

    console.log('[spec] a reload keeps the choices');
    await page.reload();
    await expect(random).not.toBeChecked();
    await expect(regent).toBeChecked();
    await expect(lordKarak).toBeChecked();
    await expect(page.getByTestId('hero-wizard')).toHaveAttribute('aria-checked', 'true');

    console.log('[spec] Regent off locks Lord Karak (the choice is kept for later, a game gets it only with Regent)');
    await page.getByTestId('regent-toggle').click();
    await expect(lordKarak).toBeDisabled();
    await expect(page.getByTestId('hero-barbarian')).toHaveCount(0);
  });

  const pickLanguage = async (page: Page, code: string) => {
    await page.locator('.language-switch__ring').first().click();
    await page.locator('.language-switch__item', { has: page.locator('.language-switch__code', { hasText: code.toUpperCase() }) }).first().click();
    await expect(page.locator('html')).toHaveAttribute('lang', code);
  };

  test('language switch', async ({ page }) => {
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    for (const code of ['ru', 'pl']) {
      console.log(`[spec] language ${code}`);
      await pickLanguage(page, code);
      await snap(page, SPEC, `lobby-${code}`, { phone: code === 'ru' });
    }
    console.log('[spec] a reload keeps the language');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('lang', 'pl');
    await pickLanguage(page, 'en');
  });

  test('rules book', async ({ page }) => {
    await page.locator('.rules-button').click();
    await expect(page).toHaveURL(/\/rules$/);
    await expect(page.locator('.rules-toc')).toBeVisible();
    await snap(page, SPEC, 'rules', { phone: true });

    for (const id of ['battle', 'regent', 'tips']) {
      console.log(`[spec] contents → #${id}`);
      await page.locator(`.rules-toc a[href="#${id}"]`).click();
      await expect(page.locator(`#${id}`)).toBeInViewport({ timeout: 5000 });
      await expect(page.locator(`.rules-toc a[href="#${id}"]`)).toHaveClass(/active/, { timeout: 5000 });
    }
    await expect(page.getByTestId('rules-regent')).toBeVisible();
    await expect(page.getByTestId('rules-lord-karak')).toBeVisible();
    await snap(page, SPEC, 'rules-regent');

    await page.locator('.rules-back').click();
    await expect(page.locator('.lobby-caption')).toBeVisible();
  });

  test('Hall of fame', async ({ page }) => {
    await page.locator('.leaderboard-button').click();
    await expect(page).toHaveURL(/\/leaderboard$/);
    await expect(page.locator('.leaderboard-table')).toBeVisible();
    await snap(page, SPEC, 'hall-of-fame', { phone: true });

    console.log('[spec] sort by games, then by victories');
    await page.locator('th.games.sortable').click();
    await expect(page.locator('th.games .sort-indicator')).toBeVisible();
    await page.locator('th.victories.sortable').click();
    await expect(page.locator('th.victories .sort-indicator')).toBeVisible();

    await page.locator('.leaderboard-back').click();
    await expect(page.locator('.lobby-caption')).toBeVisible();
  });

  test('404', async ({ page }) => {
    await page.goto(`${API_URL}/no-such-place`);
    await expect(page.locator('.not-found h1')).toHaveText(/404/);
    await snap(page, SPEC, 'not-found', { phone: true });
    await page.locator('.home-button').click();
    await expect(page.locator('.lobby-caption')).toBeVisible();
  });

  test('a game against the AI from the lobby, with the AI playback', async ({ page }) => {
    test.setTimeout(240000);
    // the human plays without a hero, so no hero dialog (Fateweaver, Stealth…) interrupts the short turn
    await page.getByTestId('random-heroes-toggle').click();
    await page.getByTestId('hero-none').click();
    // a fresh browser has no name yet: the name card stands in for the big button
    await page.getByTestId('name-card-input').fill('Tester');
    await page.getByTestId('name-card-save').click();
    await expect(page.getByTestId('account-line')).toContainText('Tester');
    await page.locator('.primary-button').click();
    await expect(page).toHaveURL(/\/game\//, { timeout: 20000 });
    await GameHelpers.waitForGameLoad(page).catch(() => {});
    await page.waitForFunction(() => document.querySelector('h3')?.textContent?.includes('Turn:'), null, { timeout: 20000 });
    await snap(page, SPEC, 'game-start', { phone: true, settleMs: 1500 });

    const playback = page.locator('.ai-playback');
    const myEndTurn = page.locator(END_TURN);

    // The human may start: play a short turn (one tile, a fight if any) and hand over
    if (await visibleWithin(myEndTurn.first(), 4000)) {
      console.log('[spec] the human starts: place one tile and end the turn');
      await GameHelpers.placeTile(page, 0, 0);
      if (await visibleWithin(page.locator('.battle-overlay'), 6000)) {
        await waitForFight(page);
        // a mummy: choose who carries the Curse of Karak first
        const curse = page.locator('[data-testid^="curse-player-"]').first();
        if (await visibleWithin(curse, 500)) {
          await curse.click();
          await page.locator('.battle-overlay .battle-footer.footer-reveal').waitFor({ state: 'visible', timeout: 10000 });
        }
        await snap(page, SPEC, 'first-fight');
        await GameHelpers.continueBattle(page, 'close');
        await page.locator('.battle-overlay').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
      }
      for (const overlay of ['.item-pickup-overlay .btn-leave', '.inventory-full-overlay .btn-secondary', '.missing-key-dialog .close-button']) {
        const button = page.locator(overlay).first();
        if (await visibleWithin(button, 1500)) await button.click();
      }
      if (await visibleWithin(myEndTurn.first(), 3000)) {
        await myEndTurn.first().click();
      }
    }

    console.log('[spec] the AI turn plays back');
    await expect(playback).toBeVisible({ timeout: 30000 });
    await snap(page, SPEC, 'ai-playback', { phone: true, settleMs: 200 });
    // slow first, so the playback lasts long enough to try the other speeds
    let switched = 0;
    for (const label of ['Slow', 'Fast', 'Normal']) {
      const button = page.locator(`.speed-controls .speed-btn[aria-label="${label}"]`);
      const clicked = await button.click({ timeout: 3000 }).then(() => true).catch(() => false);
      if (!clicked) break; // the AI turn already ended
      await expect(button).toHaveClass(/active/);
      switched++;
    }
    console.log(`[spec] speeds switched: ${switched}`);
    expect(switched).toBeGreaterThan(0);

    console.log('[spec] the turn comes back to the human');
    await expect(myEndTurn.first()).toBeVisible({ timeout: 120000 });
    await snap(page, SPEC, 'after-ai-turn', { settleMs: 1000 });
  });
});
