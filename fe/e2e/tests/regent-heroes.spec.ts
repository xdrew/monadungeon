import { test, expect } from '@playwright/test';
import { v4 as uuidv4 } from 'uuid';
import { GameHelpers } from '../utils/game-helpers';
import { snap } from '../utils/screens';
import {
  apiGame, apiPlayer, endTurn, moveTo, packTypes, position, startGame, stopTestMode, switchTo,
  takeReward, turnNumber, waitForFight, waitForFightClosed, watchPage, END_TURN,
} from '../utils/hotseat';

/**
 * The Karak: Regent heroes: Valduk, Baron Markul, Hanna, Lady Lorraine (regent: true).
 * Fight arithmetic: 2 dice + weapons + bonuses; more than the monster's strength wins, equal is a draw.
 */
const SPEC = 'regent-heroes';

const spellTypes = async (request: Parameters<typeof apiPlayer>[0], gameId: string, playerId: string) =>
  ((await apiPlayer(request, gameId, playerId)).inventory.spells || []).map((item) => item.type).sort();

test.describe('Regent heroes', () => {
  test.beforeEach(async ({ page }) => { test.setTimeout(150000); watchPage(page); });
  test.afterEach(async ({ request }) => stopTestMode(request));

  test('Barbarian: Berserk at 2 HP, Tireless leaves the loot, the heal limit stops a fountain at 4', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      // 2 + 2 = 4, Berserk +2 at 2 HP → 6 beats the rat's 5
      diceRolls: [2, 2],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_rat', 'giant_rat'],
      playerConfigs: { [a]: { hero: 'barbarian', hp: 2 }, [b]: {} },
    });

    console.log('[spec] heal limit 4 in the ring popover');
    await page.locator('button', { hasText: /heal limit/i }).first().click();
    await page.getByTestId('heal-limit').selectOption('4');
    await expect.poll(async () => (await apiPlayer(request, gameId, a)).healLimit, { timeout: 8000 }).toBe(4);
    await snap(page, SPEC, 'barbarian-heal-limit', { phone: true });

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await expect(page.getByTestId('berserk-note')).toBeVisible();
    await expect(page.locator('.victory-title')).toBeVisible();
    await expect(page.getByTestId('tireless-continue')).toBeVisible();
    await snap(page, SPEC, 'barbarian-berserk-tireless', { phone: true });

    console.log('[spec] Tireless: leave the dagger and keep moving');
    const room = await position(request, gameId, a);
    await page.getByTestId('tireless-continue').click();
    await waitForFightClosed(page);
    expect(await turnNumber(page)).toBe(1);
    expect(await packTypes(request, gameId, a)).toEqual([]);
    expect((await apiGame(request, gameId)).field.items[room]?.type).toBe('dagger');

    console.log('[spec] back to the start fountain and end the turn there: healed up to 4, not 5');
    await moveTo(page, 0, 0);
    await expect(page.locator(END_TURN).first()).toBeVisible();
    await endTurn(page);
    await expect.poll(async () => (await apiPlayer(request, gameId, a)).hp, { timeout: 10000 }).toBe(4);
  });

  test('Battle Mage: Sword Master, Lightning Attack wins; a lost one retreats', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      diceRolls: [
        1, 1, // T1 B loses to the skeleton warrior (9): it stays on its tile
        3, 4, // T2 A Lightning: 7 + sword 3 (Sword Master) = 10 beats 9
        1, 1, // T3 B loses to the skeleton king (10)
        1, 2, // T4 A Lightning: 3 + 3 + 3 (two swords) = 9 loses to 10 → retreat
      ],
      tileSequence: ['fourSideRoom', 'fourSideRoom', 'fourSideRoom'],
      itemSequence: ['skeleton_warrior', 'skeleton_king', 'giant_rat'],
      playerConfigs: { [b]: {}, [a]: { hero: 'battle_mage', inventory: ['sword'] } },
    });

    console.log('[spec] B uncovers a skeleton warrior and loses');
    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await GameHelpers.continueBattle(page, 'close');
    await waitForFightClosed(page);

    await switchTo(page, a);
    const warrior = Object.entries((await apiGame(request, gameId)).field.items)
      .find(([, item]: [string, any]) => item.name === 'skeleton_warrior' && !item.guardDefeated)?.[0] as string;
    console.log(`[spec] A strikes the warrior at ${warrior} with Lightning`);
    await page.getByTestId('lightning-attack-btn').click();
    await expect(page.getByTestId('lightning-banner')).toBeVisible();
    await snap(page, SPEC, 'battle-mage-lightning-targets', { phone: true });
    await page.getByTestId(`lightning-target-${warrior}`).click();
    await waitForFight(page);
    await expect(page.locator('.victory-title')).toBeVisible();
    await expect(page.getByTestId('sword-master-note')).toBeVisible();
    await expect(page.locator('.battle-overlay .score__detail').first()).toContainText('Sword Master +1');
    await snap(page, SPEC, 'battle-mage-sword-master-win');
    await takeReward(page);
    await waitForFightClosed(page);
    expect(await packTypes(request, gameId, a)).toEqual(['sword', 'sword']);

    console.log('[spec] B uncovers a skeleton king and loses; A strikes it and loses');
    await switchTo(page, b);
    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await GameHelpers.continueBattle(page, 'close');
    await waitForFightClosed(page);

    await switchTo(page, a);
    const king = Object.entries((await apiGame(request, gameId)).field.items)
      .find(([, item]: [string, any]) => item.name === 'skeleton_king' && !item.guardDefeated)?.[0] as string;
    await page.getByTestId('lightning-attack-btn').click();
    await page.getByTestId(`lightning-target-${king}`).click();
    await waitForFight(page);
    await expect(page.locator('.defeat-title')).toBeVisible();
    await snap(page, SPEC, 'battle-mage-lightning-lost');
    await page.locator('.battle-overlay .btn-defeat').first().click();
    await waitForFightClosed(page);
    await expect(page.getByTestId('retreat-banner')).toBeVisible();
    await snap(page, SPEC, 'battle-mage-retreat-choice', { phone: true });
    const choice = page.locator('.portal-target[data-testid^="retreat-"]').first();
    const to = ((await choice.getAttribute('data-testid')) || '').replace('retreat-', '');
    await choice.click();
    await expect.poll(() => position(request, gameId, a), { timeout: 10000 }).toBe(to);
    // the lost fight costs 1 HP; a retreat onto a fountain heals it back
    const fountains: string[] = (await apiGame(request, gameId)).field.healingFountainPositions || [];
    expect((await apiPlayer(request, gameId, a)).hp).toBe(fountains.includes(to) ? 5 : 4);
  });

  test('Acrobat: a dagger goes into a spell slot, Sprint walks more than 4 steps', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      // 3 + 3 + two swords 4 = 10 beats the rat
      diceRolls: [3, 3],
      tileSequence: ['fourSideRoom', 'fourSideRoom'],
      itemSequence: ['giant_rat', 'giant_rat'],
      playerConfigs: { [a]: { hero: 'acrobat', inventory: ['sword', 'sword'] }, [b]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await takeReward(page);
    await waitForFightClosed(page);
    await expect(page.locator('.inventory-full-overlay')).toHaveCount(0);
    expect(await spellTypes(request, gameId, a)).toEqual(['dagger']);
    await snap(page, SPEC, 'acrobat-dagger-in-spell-slot');

    const room = await position(request, gameId, a);
    const [rx, ry] = room.split(',').map(Number);
    await switchTo(page, b);
    await endTurn(page);
    await switchTo(page, a);

    console.log('[spec] Sprint: 5 steps between the room and the start');
    console.log(`[spec] positions before the sprint: ${JSON.stringify((await apiGame(request, gameId)).field.playerPositions)}`);
    await page.getByTestId('sprint-btn').click();
    await expect.poll(async () => (await apiGame(request, gameId)).state.sprint, { timeout: 8000 }).toBe(true);
    await expect(page.getByTestId('sprint-badge')).toBeVisible();
    await snap(page, SPEC, 'acrobat-sprint', { phone: true });
    for (let step = 0; step < 5; step++) {
      if (step % 2 === 0) await moveTo(page, 0, 0); else await moveTo(page, rx, ry);
    }
    await expect.poll(() => position(request, gameId, a), { timeout: 8000 }).toBe('0,0');
    expect(await turnNumber(page)).toBe(3);
    await expect(page.locator(END_TURN).first()).toBeVisible();
  });

  test('Ranger: Bear Attack counts 1s as 6s, Listening uncovers a room without entering', async ({ page, request }) => {
    const gameId = uuidv4(); const a = uuidv4(); const b = uuidv4();
    await startGame(page, request, {
      gameId,
      regent: true,
      // 1 + 1 → 6 + 6 = 12 beats the skeleton warrior (9)
      diceRolls: [1, 1],
      tileSequence: ['fourSideRoom', 'fourSideRoom', 'fourSideRoom'],
      itemSequence: ['skeleton_warrior', 'giant_rat', 'giant_rat'],
      playerConfigs: { [a]: { hero: 'ranger' }, [b]: {} },
    });

    await GameHelpers.placeTile(page, 0, 0);
    await waitForFight(page);
    await expect(page.getByTestId('bear-attack-note')).toBeVisible();
    await expect(page.locator('.victory-title')).toBeVisible();
    await snap(page, SPEC, 'ranger-bear-attack', { phone: true });
    await takeReward(page);
    await waitForFightClosed(page);

    await switchTo(page, b);
    await endTurn(page);
    await switchTo(page, a);

    console.log('[spec] Listening: place the next room, stay put, the rat shows on it');
    const here = await position(request, gameId, a);
    await page.getByTestId('listen-toggle').click();
    await expect(page.getByTestId('listen-toggle')).toHaveAttribute('aria-pressed', 'true');
    await snap(page, SPEC, 'ranger-listening-on');
    await GameHelpers.placeTile(page, 0, 0);
    await expect(page.locator('.battle-overlay')).toHaveCount(0);
    expect(await position(request, gameId, a)).toBe(here);
    const rats = Object.values((await apiGame(request, gameId)).field.items).filter((item: any) => item.name === 'giant_rat' && !item.guardDefeated);
    expect(rats.length).toBe(1);
    await snap(page, SPEC, 'ranger-listened');
  });
});
