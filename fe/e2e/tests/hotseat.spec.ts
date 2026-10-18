import { test, expect, Page } from '@playwright/test';
import { snap } from '../utils/screens';
import { API_URL, END_TURN, visibleWithin, watchPage } from '../utils/hotseat';

/**
 * Hot-seat from the lobby: two humans (the account and a named guest) and the AI at one screen. No test mode: the
 * turn order is rolled. The screen is handed over behind the "Pass the screen" curtain between two humans' turns,
 * the AI's turn plays back in between, and each human sees their own turn only after revealing it.
 */
const SPEC = 'hotseat';

/** The curtain of the next human: waits through the AI's turn and its playback. */
async function nextCurtain(page: Page, timeout = 90000): Promise<string> {
  const curtain = page.getByTestId('hotseat-curtain');
  await expect(curtain).toBeVisible({ timeout });
  const player = (await curtain.getAttribute('data-player')) || '';
  console.log(`[spec] the curtain: ${player}`);
  return player;
}

test.describe('Hot-seat', () => {
  test.beforeEach(async ({ page }) => {
    watchPage(page);
    await page.goto(API_URL);
    await page.evaluate(() => {
      for (const key of ['randomHeroes', 'regentExpansion', 'lordKarakVariant', 'selectedHero', 'lobby.tab']) localStorage.removeItem(key);
      sessionStorage.clear();
    });
    await page.reload();
  });

  test('two players and the AI at one screen: the curtain hands the screen over', async ({ page }) => {
    test.setTimeout(300000);
    await page.getByTestId('name-card-input').fill('Host');
    await page.getByTestId('name-card-save').click();
    await expect(page.getByTestId('account-line')).toContainText('Host');

    console.log('[spec] the lobby tab: two seats, the account and a guest; heroes picked, the AI added');
    await page.getByTestId('lobby-tab-hotseat').click();
    await expect(page.getByTestId('hotseat-setup')).toBeVisible();
    await page.getByTestId('random-heroes-toggle-input').uncheck();
    await expect(page.getByTestId('hotseat-seat-0')).toContainText('Host');
    await page.getByTestId('hotseat-name-1').fill('Mira');
    // no hero for the humans: no hero dialog interrupts a short turn; a hero taken by one seat is not offered to the other
    await page.getByTestId('hotseat-hero-0').selectOption('warrior');
    await expect(page.getByTestId('hotseat-hero-1').locator('option[value="warrior"]')).toBeDisabled();
    await page.getByTestId('hotseat-hero-0').selectOption({ index: 0 });
    await page.getByTestId('hotseat-add-ai').click();
    await expect(page.getByTestId('hotseat-seat-2')).toBeVisible();
    // the lobby fits a 1280x720 screen with three seats: no page scroll (the seats scroll inside the panel)
    await page.setViewportSize({ width: 1280, height: 720 });
    const pageScrolls = await page.evaluate(() => [document.documentElement, document.body]
      .some((el) => el.scrollHeight > el.clientHeight + 1));
    expect(pageScrolls).toBe(false);
    await expect(page.getByTestId('hotseat-start')).toBeInViewport({ ratio: 1 });
    await snap(page, SPEC, 'lobby-hotseat', { phone: true });

    await page.getByTestId('hotseat-start').click();
    await expect(page).toHaveURL(/\/game\//, { timeout: 30000 });

    console.log('[spec] two humans take turns behind the curtain, the AI plays in between');
    const seen = new Set<string>();
    let previous = '';
    for (let handover = 0; handover < 3 && seen.size < 2; handover++) {
      const player = await nextCurtain(page);
      expect(player).not.toBe(previous);
      if (handover === 0) {
        // the curtain covers the last screen: the pack and End Turn wait for the reveal
        await expect(page.getByTestId('hotseat-reveal')).toBeFocused();
        await snap(page, SPEC, 'curtain', { phone: true });
      }
      seen.add(player);
      previous = player;
      await page.getByTestId('hotseat-reveal').click();
      await expect(page.getByTestId('hotseat-curtain')).toHaveCount(0);
      const endTurn = page.locator(END_TURN).first();
      await expect(endTurn).toBeVisible({ timeout: 15000 });
      if (handover === 0) await snap(page, SPEC, 'revealed-turn');
      await endTurn.click();
      // the stunned or an item plaque never comes on a turn that only ends; wait for the turn to pass
      await visibleWithin(page.getByTestId('hotseat-curtain'), 2000);
    }
    expect([...seen].sort()).toEqual(['Host', 'Mira']);
  });
});
