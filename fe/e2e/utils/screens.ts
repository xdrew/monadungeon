import { Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Screenshots of every screen a spec reaches, for the design review:
 * test-results/screens/<spec>/<NN>-<name>.png (and <NN>-<name>.phone.png at 390×844).
 */
const counters: Record<string, number> = {};
const DESKTOP = { width: 1280, height: 720 };
const PHONE = { width: 390, height: 844 };

const target = (spec: string, name: string, suffix = '') => {
  const dir = path.join('test-results', 'screens', spec);
  fs.mkdirSync(dir, { recursive: true });
  return path.join(dir, `${name}${suffix}.png`);
};

const settle = async (page: Page, ms: number) => {
  await page.evaluate(() => document.fonts.ready).catch(() => {});
  await page.waitForTimeout(ms);
};

export async function snap(
  page: Page,
  spec: string,
  name: string,
  opts: { phone?: boolean; waitFor?: string; settleMs?: number; fullPage?: boolean } = {}
): Promise<void> {
  if (opts.waitFor) {
    await page.locator(opts.waitFor).first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {
      console.log(`[snap] ${spec}/${name}: ${opts.waitFor} not visible, snapping anyway`);
    });
  }
  counters[spec] = (counters[spec] || 0) + 1;
  const numbered = `${String(counters[spec]).padStart(2, '0')}-${name}`;
  await settle(page, opts.settleMs ?? 400);
  await page.screenshot({ path: target(spec, numbered), fullPage: opts.fullPage === true });
  console.log(`[snap] ${spec}/${numbered}`);
  if (opts.phone) {
    const before = page.viewportSize() || DESKTOP;
    await page.setViewportSize(PHONE);
    await settle(page, 600);
    await page.screenshot({ path: target(spec, numbered, '.phone'), fullPage: opts.fullPage === true });
    await page.setViewportSize(before);
    await settle(page, 300);
  }
}
