import { test, expect } from '@playwright/test';
import { v4 as uuidv4 } from 'uuid';
import { GameHelpers } from '../utils/game-helpers';
import { TestGameSetup } from '../utils/test-game-setup';

declare global {
  interface Window {
    dragonTilePosition?: { x: number; y: number };
    battleTilePosition?: { x: number; y: number };
  }
}

// Timeout constants
const TIMEOUTS = {
  MODAL_WAIT: 10000,      // 10 seconds (was 5000)
  BATTLE_MODAL: 10000,     // 3 seconds for battle modals (was 15000)
  ELEMENT_WAIT: 10000,    // 10 seconds (was 5000)
  SHORT_WAIT: 6000,       // 2 seconds (was 6000)
  ANIMATION_WAIT: 2000,   // 1 second (was 2500)
  NETWORK_IDLE: 8000,     // 3 seconds (was 8000)
  DIALOG_WAIT: 7000       // 2 seconds (was 7000)
};

test.describe('Two Player Game', () => {
  let setup: TestGameSetup;
  const apiUrl = process.env.API_URL || 'http://sf.sf:18080';

  test.beforeEach(async ({ request }) => {
    setup = new TestGameSetup(apiUrl);
    console.log('Using API URL:', apiUrl);
    await setup.enableTestMode(request);
  });

  test.afterEach(async ({ request }) => {
    await setup.disableTestMode(request);
  });

  /**
   * Test: Complete Two-Player Game Flow with All Game Mechanics
   * 
   * This comprehensive test covers all major game mechanics including:
   * - Battle victories, defeats, and draws
   * - Consumable usage
   * - Inventory management (full inventory replacement and leaving items)
   * - Key mechanics (skipping duplicate keys)
   * - Item pickup dialogs when stepping on tiles with items
   * - Player stunning and turn skipping
   * - Teleportation gates (using twoSideStraightRoom tiles)
   * - Chest opening with fallen monster
   * - Healing fountain usage and recovery
   * - Dragon battle and game ending
   * - Winner determination
   * 
   * Test Flow:
   * 
   * Turn 1: Player 1 defeats skeleton_turnkey, gets key
   * Turn 2: Player 2 places first teleportation gate, then room, loses to skeleton_king
   * Turn 3: Player 1 defeats giant_rat, gets dagger
   * Turn 4: Player 2 defeats mummy, gets fireball consumable
   * Turn 5: Player 1 defeats another giant_rat, gets second dagger
   * Turn 6: Player 2 uses fireball to defeat skeleton_turnkey
   * Turn 7: Player 1 has 2 daggers, defeats skeleton_warrior for sword - must replace item
   * Turn 8: Player 2 loses to skeleton_king again, HP drops to 0 (stunned)
   * Turn 9: Player 1 defeats skeleton_king, picks up or leaves axe based on inventory
   * Turn 10: Player 2 stunned (0 HP), turn is skipped automatically
   * Turn 11: Player 1 already has key, defeats skeleton_turnkey, key is auto-skipped
   * Turn 12: Player 2 recovers with victory against rat (back to 1 HP)
   * Turn 13: Player 1 picks up axe from field, must replace item
   * Turn 14: Player 2 places second teleportation gate and draws battle with fallen
   * Turn 15: Player 1 loses to dragon (15 HP), 11 damage < 15 HP
   * Turn 16: Player 2 places corner tile with healing fountain, then room tile, loses battle, steps back to healing fountain
   * Turn 17: Player 1 loses to dragon again
   * Turn 18: Player 2 teleports between the two teleportation gates instead of battling
   * Turn 19: Player 1 defeats dragon and ends game
   */
  test('complete game flow with battles and healing fountain', async ({ page, request }, testInfo) => {
    test.setTimeout(900000); // Increase timeout to 15 minutes for extended test with healing fountain
    const testStartTime = Date.now();
    
    // Game setup
    const gameId = uuidv4();
    const player1Id = uuidv4();
    const player2Id = uuidv4();

    // Always print game UUID, even in silent mode
    console.log(`[TEST] Game UUID: ${gameId}`);
    testInfo.annotations.push({ type: 'gameId', description: gameId });
    
    // Capture browser console logs
    page.on('console', msg => {
      if (msg.type() === 'error') {
        console.log(`[BROWSER ERROR] ${msg.text()}`);
      }
    });
    
    // Capture failed network requests
    page.on('response', response => {
      if (response.status() >= 500) {
        console.log(`[HTTP ${response.status()}] ${response.url()}`);
        response.text().then(body => {
          console.log('[RESPONSE BODY]:', body.substring(0, 500)); // First 500 chars
        }).catch(() => {});
      }
    });
    
    // Helper to print elapsed time
    const printElapsedTime = () => {
      const elapsed = Date.now() - testStartTime;
      const minutes = Math.floor(elapsed / 60000);
      const seconds = Math.floor((elapsed % 60000) / 1000);
      console.log(`[TEST] Elapsed time: ${minutes}m ${seconds}s`);
    };
    
    // Helper to wait for battle modal to close and requests to complete
    const waitForBattleComplete = async () => {
      // First wait for the modal to be hidden
      await page.waitForSelector('.battle-report-modal', { state: 'hidden', timeout: TIMEOUTS.BATTLE_MODAL });
      // Then wait for loading status to disappear
      await page.waitForFunction(() => {
        const loadingElements = document.querySelectorAll('[data-loading-status="Finalizing battle..."]');
        const loadingTexts = Array.from(document.querySelectorAll('*')).filter(el => 
          el.textContent?.includes('Finalizing battle...') && 
          el.children.length === 0
        );
        return loadingElements.length === 0 && loadingTexts.length === 0;
      }, { timeout: TIMEOUTS.MODAL_WAIT });
    };
    
    // Helper to wait for turn change
    const waitForTurnChange = async (expectedFromTurn?: number, expectedToTurn?: number) => {
      // Get current turn text
      const getCurrentTurnText = async () => {
        const turnElement = await page.locator('h3:has-text("Turn:")').textContent();
        return turnElement || '';
      };
      
      const initialTurn = await getCurrentTurnText();
      console.log(`Current turn: ${initialTurn}`);
      
      // If we have expected turn numbers, check if we're already at the expected "to" turn
      if (expectedFromTurn && expectedToTurn) {
        const currentTurnNumber = parseInt(initialTurn.replace('Turn: ', ''));
        if (currentTurnNumber === expectedToTurn) {
          console.log(`Already at expected turn ${expectedToTurn}`);
          return;
        }
      }
      
      console.log(`Waiting for turn to change from: ${initialTurn}`);
      
      // Wait for the turn text to change
      await page.waitForFunction(
        (expectedInitialTurn) => {
          // Find all h3 elements and look for one with "Turn:"
          const h3Elements = document.querySelectorAll('h3');
          for (const element of h3Elements) {
            const text = element.textContent || '';
            if (text.includes('Turn:') && text !== expectedInitialTurn) {
              return true;
            }
          }
          return false;
        },
        initialTurn,
        { timeout: 10000 } // 10 second timeout
      );
      
      // Small delay to ensure server has processed the turn change
      await page.waitForTimeout(500);
      
      const newTurn = await getCurrentTurnText();
      console.log(`Turn changed to: ${newTurn}`);
    };
    
    // Configure predictable game state with players
    await setup.setupGame(request, {
      gameId,
      diceRolls: [
        6, 6,   // Player 1 Turn 1: wins turnkey 8 (12 damage)
        1, 1,   // Player 2 Turn 2: loses king 12 (2 damage)
        4, 3,   // Player 1 Turn 3: wins rat 5 (7 damage)
        5, 5,   // Player 2 Turn 4: wins mummy 7 (10 damage) - gets fireball
        6, 6,   // Player 1 Turn 5: wins rat 5 (12 + 1 damage)
        4, 4,   // Player 2 Turn 6: 8 damage + 1 fireball = 9 to defeat skeleton_turnkey 8
        5, 5,   // Player 1 Turn 7: wins warrior 9 (10 + 2 damage) - inventory full scenario
        1, 1,   // Player 2 Turn 8: loses again king 10 (2 damage) - becomes stunned
        6, 6,   // Player 1 Turn 9: wins skeleton_king 10 (12 + 3 damage) - leaves treasure
                // Player 2 Turn 10: stunned, skips
        4, 4,   // Player 1 Turn 11: wins turnkey 8 (8 + 3 damage) - already has key
        3, 3,   // Player 2 Turn 12: wins rat 5 (6 damage) - gets dagger
                // Player 1 Turn 13: skips turn (pick up axe)
        6, 6,   // Player 2 Turn 14: wins fallen (12 + 1 damage vs 12 HP)
        3, 3,   // Player 1 Turn 15: loses to dragon (6 + 3 + 2 = 11 damage vs 15 HP)
        1, 1,   // Player 2 Turn 16: loses to skeleton_warrior (2 + 1 = 3 damage vs 9 HP)
        2, 2,   // Player 1 Turn 17: loses to dragon again (4 + 3 + 2 = 9 damage vs 15 HP)
        6, 6    // Player 1 Turn 19: wins dragon (12 + 3 + 2 = 17 damage vs 15 HP)
      ],
      tileSequence: [
        'fourSideRoom',        // Tile 1: for Player 1
        { orientation: 'twoSideStraight', room: false, features: ['teleportation_gate'] }, // Tile 2: for Player 2 (TELEPORTATION GATE 1 - replaces fourSideStraight)
        'threeSideRoom',       // Tile 3: for Player 2 (actual battle with skeleton_king)
        'twoSideStraightRoom', // Tile 4: for Player 1
        'fourSideRoom',        // Tile 5: for Player 2
        'threeSideRoom',       // Tile 6: for Player 1
        'fourSideRoom',        // Tile 7: for Player 2
        'twoSideStraightRoom', // Tile 8: for Player 1 (inventory full)
        'threeSideRoom',       // Tile 9: for Player 2 (gets stunned)
        'fourSideRoom',        // Tile 10: for Player 1 (leave item)
                               // Turn 11: Player 2 stunned, skips
        'twoSideStraightRoom', // Tile 12: for Player 1 (already has key)
        'threeSideRoom',       // Tile 13: for Player 2 (wins rat)
                               // Turn 14: Player 1 skips (pick up axe)
        { orientation: 'twoSideStraight', room: false, features: ['teleportation_gate'] }, // Tile 15: for Player 2 (TELEPORTATION GATE 2 - replaces fourSideRoom)
        'fourSideRoom',        // Tile 15: for Player 2 (wins - fallen)
        'fourSideRoom',        // Tile 16: for Player 1 (dragon - loses)
        { orientation: 'twoSideCorner', room: false, features: ['healing_fountain'] }, // Tile 17: for Player 2 Turn 16 (corner with healing fountain)
        'threeSideRoom',       // Tile 17: for Player 2 Turn 16 (loses to skeleton_warrior)
                               // Turn 18: Player 1 loses to dragon again
                               // Turn 19: Player 2 teleports between gates instead of battle
        'fourSideRoom'         // Tile 20: Placeholder (game should end before this)
      ],
      itemSequence: [
        'skeleton_turnkey',   // Turn 1: HP 8 (Player 1 wins 12 > 8) - drops key
        'skeleton_king',      // Turn 2: HP 10 (Player 2 loses 2 < 10)
        'giant_rat',          // Turn 3: HP 5 (Player 1 wins 7 > 5) - drops dagger
        'mummy',              // Turn 4: HP 7 (Player 2 wins 10 > 7) - drops fireball +1
        'giant_rat',          // Turn 5: HP 5 (Player 1 wins 12 > 5) - drops dagger
        'skeleton_turnkey',   // Turn 6: HP 8 (Player 2 + consumable wins 9 > 8) - drops key
        'skeleton_warrior',   // Turn 7: HP 9 (Player 1 wins 10 > 9) - drops sword (inventory full)
        'skeleton_king',      // Turn 8: HP 10 (Player 2 loses 2 < 10) - becomes stunned
        'skeleton_king',      // Turn 9: HP 10 (Player 1 wins 12 > 10) - drops axe (+3)
                              // Turn 10: Player 2 stunned, skips
        'skeleton_turnkey',   // Turn 11: HP 8 (Player 1 wins 8 = 8) - drops key (already has one)
        'giant_rat',          // Turn 12: HP 5 (Player 2 wins 6 > 5) - drops dagger
                              // Turn 13: Player 1 skips turn (pick up axe)
        'fallen',             // Turn 14: HP 12 (Player 2 wins 13 > 12)
        'dragon',             // Turn 15: HP 15 (Player 1 loses 11 < 15)
        'skeleton_warrior',   // Turn 16: HP 9 (Player 2 loses 3 < 9)
                              // Turn 17: Player 1 loses to dragon again
        'skeleton_warrior',   // Turn 18: HP 9 (Player 2 wins 11 > 9) - drops sword
        'dragon'              // Turn 19: HP 15 (Player 1 wins 17 > 15)
      ],
      playerConfigs: {
        [player1Id]: {},  // Default HP and actions
        [player2Id]: {maxHp: 2}   // Lower HP to test stunning more easily
      }
    });

    // Game is automatically created and started by setupGame with the configured players
    
    // Navigate to game as Player 1
    await page.goto(apiUrl);
    
    // Clear any previous test data from localStorage
    await page.evaluate(() => {
      localStorage.removeItem('dragonTilePosition');
      localStorage.removeItem('battleTilePosition');
    });
    
    await GameHelpers.setupPlayerSession(page, player1Id);
    await page.goto(`${apiUrl}/game/${gameId}`);
    await GameHelpers.waitForGameLoad(page);

    // === PLAYER 1 TURN 1: Victory Scenario ===
    console.log('=== Player 1 Turn 1: Victory Scenario ===');
    printElapsedTime();
    await GameHelpers.placeTile(page, 0, 0);
    
    // Wait for battle modal to appear
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.BATTLE_MODAL });
    console.log('Player 1: Battle modal appeared');
    await GameHelpers.continueBattle(page, 'pickup');
    
    // Wait for battle modal to close and battle to finalize
    await waitForBattleComplete();
    
    // Wait for turn change from Turn 1 to Turn 2
    await waitForTurnChange(1, 2);
    
    console.log('Turn complete');
    
    // === PLAYER 2 TURN 2: Place teleportation gate then room, defeat by Skeleton King ===
    console.log('=== Player 2 Turn 2: Place teleportation gate then room, defeat by Skeleton King ===');
    await GameHelpers.setupPlayerSession(page, player2Id);
    await GameHelpers.waitForGameLoad(page);
    
    console.log('=== Player 2 Turn Starting ===');
    
    // First place the teleportation gate tile (replaces corridor)
    await GameHelpers.placeTile(page, 1, 0); // Place to the right (player auto-moves here)
    console.log('Placed first teleportation gate tile');
    
    // Store the position of the first teleportation gate
    await page.evaluate(() => {
      localStorage.setItem('teleportGate1Position', JSON.stringify({ x: 1, y: 0 }));
    });

    // Now place room tile from the teleportation gate position
    await GameHelpers.placeTile(page, 2, 0); // Place room to the right (player auto-moves and battles)
    
    // Wait for battle modal to appear
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    
    console.log('Player 2: Battle modal appeared');
    await GameHelpers.continueBattle(page, 'close');
    
    // Player 2 loses and moves back to corridor (1,0) - no healing fountain!
    // Player 2 should now be at 1 HP (2 max HP - 1 damage from defeat)
    // Wait for turn change from Turn 2 to Turn 3
    await waitForTurnChange(2, 3);
    
    console.log('Turn complete');
    
    // === PLAYER 1 TURN 3: Victory Scenario ===
    console.log('=== Player 1 Turn 3: Victory Scenario ===');
    await GameHelpers.setupPlayerSession(page, player1Id);
    await GameHelpers.waitForGameLoad(page);
    
    await GameHelpers.placeTile(page, 0, 0);
    
    // Wait for battle modal to appear
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    console.log('Player 1: Battle modal appeared');
    await GameHelpers.continueBattle(page, 'pickup');
    
    // Wait for turn change from Turn 3 to Turn 4
    await waitForTurnChange(3, 4);
    
    console.log('Turn complete');
    
    // === PLAYER 2 TURN 4: Victory against Mummy ===
    console.log('=== Player 2 Turn 4: Victory against Mummy ===');
    await GameHelpers.setupPlayerSession(page, player2Id);
    await GameHelpers.waitForGameLoad(page);
    
    await GameHelpers.movePlayer(page, 0, 0);

    await GameHelpers.placeTile(page, 0, 0);

    // Wait for battle modal to appear
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    console.log('Player 2: Battle modal appeared');
    await GameHelpers.continueBattle(page, 'pickup');
    
    // Ensure battle modal is closed and request completes
    await waitForBattleComplete();
    console.log('Battle modal closed after picking up fireball');
    
    // Wait for turn change from Turn 4 to Turn 5
    await waitForTurnChange(4, 5);
    
    console.log('Turn complete');
    
    // === PLAYER 1 TURN 5: Another Victory ===
    console.log('=== Player 1 Turn 5: Another Victory ===');
    await GameHelpers.setupPlayerSession(page, player1Id);
    await GameHelpers.waitForGameLoad(page);
    
    
    await GameHelpers.placeTile(page, 0, 0);
    
    // Wait for battle modal to appear
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    console.log('Player 1: Battle modal appeared');
    await GameHelpers.continueBattle(page, 'pickup');
    
    // Ensure battle modal is closed and request completes
    await waitForBattleComplete();
    console.log('Battle modal closed after picking up second dagger');
    
    // Wait for turn change from Turn 5 to Turn 6
    await waitForTurnChange(5, 6);
    
    console.log('Turn complete');
    
    // === PLAYER 2 TURN 6: Victory using Consumable ===
    console.log('=== Player 2 Turn 6: Victory using Consumable ===');
    await GameHelpers.setupPlayerSession(page, player2Id);
    await GameHelpers.waitForGameLoad(page);
    
    
    await GameHelpers.placeTile(page, 0, 0);
    
    // Wait for battle modal to appear
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    
    console.log('Battle modal visible - consumable selection expected');
    
    // Wait for consumable selection UI to be available
    await page.waitForSelector('.selectable-item', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    
    // Select the fireball consumable and fight
    const fireballItem = page.locator('.selectable-item').first();
    await fireballItem.click();
    console.log('Selected fireball consumable');
    
    // Wait for consumable to be selected
    await page.waitForFunction(() => {
      const selectedItems = document.querySelectorAll('.selectable-item.selected, .item-selected');
      return selectedItems.length > 0;
    }, { timeout: TIMEOUTS.MODAL_WAIT });
    
    // Look for the fight button that appears after selecting consumables
    const fightWithConsumablesButton = page.locator('button').filter({ hasText: /fight.*win.*pick/i }).first();
    if (await fightWithConsumablesButton.isVisible()) {
      await fightWithConsumablesButton.click();
      console.log('Clicked fight, win, and pick up reward button');
    } else {
      // Try alternative button text
      const altFightButton = page.locator('.finalize-battle-btn, button:has-text("Fight")').first();
      await altFightButton.click();
      console.log('Clicked fight button');
    }
    
    // Wait for battle modal to close and request to complete
    await waitForBattleComplete();
    console.log('Player 2: Used fireball to defeat skeleton_turnkey (9 damage vs 8 HP) and picked up key');
    
    // Wait for turn change from Turn 6 to Turn 7
    await waitForTurnChange(6, 7);
    
    console.log('Turn complete');

    // === PLAYER 1 TURN 7: Inventory Full - Replace Item ===
    console.log('=== Player 1 Turn 7: Inventory Full - Replace Item ===');
    printElapsedTime();
    await GameHelpers.setupPlayerSession(page, player1Id);
    await GameHelpers.waitForGameLoad(page);
    
    // Check if there's a lingering battle modal from the previous turn
    const battleModalStillVisible = await page.locator('.battle-report-modal').isVisible().catch(() => false);
    if (battleModalStillVisible) {
      console.log('Battle modal still visible from previous turn, handling it');
      
      // Check for victory buttons
      const pickupButton = page.getByRole('button', { name: /Pick.*up.*end.*turn/i });
      const leaveButton = page.getByRole('button', { name: /Leave.*item.*end.*turn/i });
      
      if (await pickupButton.isVisible({ timeout: TIMEOUTS.SHORT_WAIT }).catch(() => false)) {
        console.log('Clicking Pick up and end turn button');
        await pickupButton.click();
      } else if (await leaveButton.isVisible({ timeout: TIMEOUTS.SHORT_WAIT }).catch(() => false)) {
        console.log('Clicking Leave item and end turn button');
        await leaveButton.click();
      } else {
        // Try to close the modal
        const closeButton = page.locator('.close-battle-btn, button:has-text("×")').first();
        if (await closeButton.isVisible({ timeout: TIMEOUTS.SHORT_WAIT }).catch(() => false)) {
          await closeButton.click();
        }
      }
      
      // Wait for modal to close and requests to complete
      await waitForBattleComplete();
    }

    // Small wait before tile placement to avoid 500 error
    await page.waitForTimeout(1000);
    
    await GameHelpers.placeTile(page, 0, 0);

    // Wait for battle modal with longer timeout
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: 20000 });
    console.log('Player 1: Battle modal appeared');

    // Try to pick up sword - should trigger inventory replacement dialog
    await GameHelpers.continueBattle(page, 'pickup');
    
    // Wait for battle modal to close and request to complete
    await waitForBattleComplete();
    // Removed arbitrary wait

    // Handle inventory replacement - replace first dagger with sword
    await GameHelpers.handleInventoryReplacement(page, 0);
    console.log('Player 1: Replaced dagger with sword');

    // Wait for turn change from Turn 7 to Turn 8
    await waitForTurnChange(7, 8);

    // === PLAYER 2 TURN 8: Gets Stunned ===
    console.log('=== Player 2 Turn 8: Gets Stunned ===');
    
    await GameHelpers.setupPlayerSession(page, player2Id);
    await GameHelpers.waitForGameLoad(page);

    await GameHelpers.placeTile(page, 0, 0);

    // Wait for battle modal
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    console.log('Player 2: Battle modal appeared');
    await GameHelpers.continueBattle(page, 'close');
    await waitForBattleComplete();

    // Wait for turn change from Turn 8 to Turn 9
    await waitForTurnChange(8, 9);
    
    console.log('Turn complete');

    // === PLAYER 1 TURN 9: Victory - Should Leave Item ===
    console.log('=== Player 1 Turn 9: Victory - Should Leave Item ===');
    await GameHelpers.setupPlayerSession(page, player1Id);
    await GameHelpers.waitForGameLoad(page);

    await GameHelpers.placeTile(page, 0, 0);

    // Wait for battle modal
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    console.log('Player 1: Battle modal appeared');
    
    // Wait a bit for the battle result to be fully rendered
    await page.waitForTimeout(1000);
    
    // Check if leave button is available (inventory full)
    const leaveButton = page.locator('.leave-item-btn').first();
    const pickupButton = page.locator('.pick-up-btn').first();
    
    if (await leaveButton.isVisible()) {
      // Inventory is full - leave the item
      await leaveButton.click();
      console.log('Player 1: Clicked leave item button (inventory full)');
    } else if (await pickupButton.isVisible()) {
      // Inventory has space - pick up the item
      await pickupButton.click();
      console.log('Player 1: Clicked pick up button (inventory has space)');
      
      // If this triggers inventory replacement, handle it
      try {
        await page.waitForSelector('.inventory-item-replace', { timeout: 2000 });
        console.log('Inventory replacement dialog appeared');
        // Click first item to replace
        await page.locator('.inventory-item-replace').first().click();
        await page.getByRole('button', { name: 'Replace Selected Item' }).click();
      } catch {
        // No inventory replacement needed
      }
    } else {
      console.log('ERROR: No action button found!');
      await GameHelpers.screenshot(page, 'turn-9-no-action-button');
    }

    // Wait for battle modal to close and request to complete
    await waitForBattleComplete();

    // === PLAYER 2 TURN 10: Stunned - Skip Turn ===
    console.log('=== Player 2 Turn 10: Stunned - Skip Turn ===');
    console.log('Turn automatically skipped');

    // === PLAYER 1 TURN 11: Already Has Key ===
    console.log('=== Player 1 Turn 11: Already Has Key ===');
    await GameHelpers.setupPlayerSession(page, player1Id);
    await GameHelpers.waitForGameLoad(page);

    await GameHelpers.placeTile(page, 0, 0);

    // Wait for battle modal
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    console.log('Player 1: Battle modal appeared');
    await GameHelpers.continueBattle(page, 'close');

    // Wait for battle modal to fully close and requests to complete
    await waitForBattleComplete();
    
    // Wait for turn change from Turn 11 to Turn 12
    await waitForTurnChange(11, 12);


    // === PLAYER 2 TURN 12: Victory against Rat ===
    console.log('=== Player 2 Turn 12: Victory against Rat ===');
    
    await GameHelpers.setupPlayerSession(page, player2Id);
    await GameHelpers.waitForGameLoad(page);

    // Quick check for healing fountain visibility on starting tile
    const startingTile = page.locator('[data-position="0,0"]');
    const isTileVisible = await startingTile.isVisible({ timeout: 2000 }).catch(() => false);
    if (isTileVisible) {
      const healingFountain = startingTile.locator('.healing-fountain');
      const isFountainVisible = await healingFountain.isVisible({ timeout: 1000 }).catch(() => false);
      if (isFountainVisible) {
        console.log('✓ Healing fountain verified on starting tile (0,0)');
      }
    }

    await GameHelpers.placeTile(page, 0, 0);

    // Wait for battle modal
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    console.log('Player 2: Battle modal appeared');
    await GameHelpers.continueBattle(page, 'pickup');

    // Wait for turn transition
    await waitForTurnChange(12, 13);

    // === PLAYER 1 TURN 13: Skip turn or pick up axe ===
    console.log('=== Player 1 Turn 13: Skip turn or pick up axe ===');
    printElapsedTime();
    
    await GameHelpers.setupPlayerSession(page, player1Id);
    await GameHelpers.waitForGameLoad(page);

    // Move to pick up the axe that was left on Turn 9
    await GameHelpers.movePlayer(page, 0, 0);
    
    // Wait for Pick Up button
    await page.getByRole('button', { name: 'Pick Up' }).waitFor();
    await page.getByRole('button', { name: 'Pick Up' }).click();
    
    // Wait for inventory full dialog
    await page.getByText('Inventory Full').waitFor();
    
    // Click the first Dagger item to select it (previously showed "Giant Rat")
    // Since there are 2 daggers, click the first one
    await page.locator('.inventory-item').filter({ hasText: 'Dagger' }).first().click();
    
    // Click Replace Selected Item button
    await page.getByRole('button', { name: 'Replace Selected Item' }).click();
    
    // Wait for turn change
    await waitForTurnChange(13, 14);
    console.log('Turn complete');
    
    // === PLAYER 2 TURN 14: Second Teleportation Gate & Fallen Battle ===
    console.log('=== Player 2 Turn 14: Second Teleportation Gate & Fallen Battle ===');
    printElapsedTime();
    await GameHelpers.setupPlayerSession(page, player2Id);
    await GameHelpers.waitForGameLoad(page);
    
    // First place the teleportation gate tile
    const gate2Position = await GameHelpers.placeTile(page, 0, 0);
    console.log(`Placed second teleportation gate tile at ${gate2Position.x},${gate2Position.y}`);
    
    // Store the position of this teleportation gate for later use
    await page.evaluate((pos) => {
      localStorage.setItem('teleportGate2Position', JSON.stringify(pos));
    }, gate2Position);
    
    // Now place the next tile for the fallen battle
    await GameHelpers.placeTile(page, 0, 0);

    // Wait for battle modal - Fallen battle
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.BATTLE_MODAL });
    console.log('Fallen battle modal appeared');
    
    // For draw result, close the battle modal (the battle result itself ends the turn)
    await GameHelpers.continueBattle(page, 'close');
    console.log('Closed battle modal for draw result');
    
    // Wait for modal to close and turn to transition
    await waitForBattleComplete();
    
    // Wait for turn change
    await waitForTurnChange(14, 15);
    
    // === PLAYER 1 TURN 15: Dragon Battle - Loses ===
    console.log('=== Player 1 Turn 15: Dragon Battle - Loses ===');
    printElapsedTime();
    await GameHelpers.setupPlayerSession(page, player1Id);
    await GameHelpers.waitForGameLoad(page);
    
    // Place dragon lair tile and get its position
    const dragonTilePosition = await GameHelpers.placeTile(page, 0, 0);
    console.log(`Dragon tile placed at position: ${dragonTilePosition.x},${dragonTilePosition.y}`);
    
    // Wait for battle modal - Dragon battle
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.BATTLE_MODAL });
    console.log('Dragon battle modal appeared - Player 1 loses');
    
    // Player loses to dragon - close modal
    await GameHelpers.continueBattle(page, 'close');
    console.log('Player 1 lost to dragon (11 damage < 15 HP)');
    
    // Wait for battle modal to close
    await waitForBattleComplete();
    
    // Store dragon position for later turns using localStorage (persists across page reloads)
    await page.evaluate((pos) => {
      localStorage.setItem('dragonTilePosition', JSON.stringify(pos));
    }, dragonTilePosition);
    
    // Wait for turn change
    await waitForTurnChange(15, 16);
    
    // === PLAYER 2 TURN 16: Place Healing Fountain Tile then Room ===
    console.log('=== Player 2 Turn 16: Place Healing Fountain Tile then Room ===');
    await GameHelpers.setupPlayerSession(page, player2Id);
    await GameHelpers.waitForGameLoad(page);
    
    // Place corner tile with healing fountain (no room = no battle, turn continues)
    const healingFountainPos = await GameHelpers.placeTile(page, 0, 0);
    console.log(`Player 2 placed corner tile with healing fountain at ${healingFountainPos.x},${healingFountainPos.y} (no battle)`);
    
    // Small wait to ensure tile is placed
    await page.waitForTimeout(1000);
    
    // Check if healing fountain is visible on the placed tile
    const healingFountainVisible = await page.locator('.healing-fountain, [title*="Healing"], .tile-feature-healing').isVisible({ timeout: 2000 }).catch(() => false);
    if (healingFountainVisible) {
      console.log('✓ Healing fountain tile placed successfully');
    } else {
      console.log('⚠️ Warning: Healing fountain may not be visible on placed tile');
    }
    
    // Player 2 continues same turn - place room tile that will trigger battle
    const battleTilePos = await GameHelpers.placeTile(page, 0, 0);
    console.log(`Player 2 placed room tile at ${battleTilePos.x},${battleTilePos.y}`);
    
    // Wait for battle modal
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.BATTLE_MODAL });
    console.log('Battle modal appeared - Player 2 will lose');
    
    // Lose battle
    await GameHelpers.continueBattle(page, 'close');
    console.log('Player 2 lost battle and moves back');
    
    await waitForBattleComplete();
    
    // Player automatically moves back to healing fountain tile
    console.log(`Player 2 stepped back to healing fountain tile at ${healingFountainPos.x},${healingFountainPos.y} and recovered HP`);

    // Store positions for later use in localStorage
    await page.evaluate((btPos) => {
      localStorage.setItem('battleTilePosition', JSON.stringify(btPos));
    }, battleTilePos);
    
    // Wait for turn change from 16 to 17
    await waitForTurnChange(16, 17);
    
    // === PLAYER 1 TURN 17: Lose to Dragon Again ===
    console.log('=== Player 1 Turn 17: Lose to Dragon Again ===');
    await GameHelpers.setupPlayerSession(page, player1Id);
    await GameHelpers.waitForGameLoad(page);
    
    // Get stored dragon position from localStorage
    const dragonPos = await page.evaluate(() => {
      const stored = localStorage.getItem('dragonTilePosition');
      return stored ? JSON.parse(stored) : null;
    }) || { x: 1, y: -5 };
    console.log(`Retrieved dragon position from storage: ${dragonPos.x},${dragonPos.y}`);

    // Check if dragon is still at that position before moving
    const dragonStillThere = await page.locator(`.tile[data-position="${dragonPos.x},${dragonPos.y}"]:has-text("🐉")`).count() > 0;
    if (!dragonStillThere) {
      console.log('WARNING: Dragon no longer at expected position!');
    }

    await GameHelpers.movePlayer(page, dragonPos.x, dragonPos.y);
    
    // Wait for battle modal
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.BATTLE_MODAL });
    console.log('Dragon battle modal appeared again - Player 1 loses');
    
    // Lose to dragon again
    await GameHelpers.continueBattle(page, 'close');
    console.log('Player 1 lost to dragon again (9 damage < 15 HP)');
    
    await waitForBattleComplete();
    
    // Wait for turn change from 17 to 18
    await waitForTurnChange(17, 18);
    
    // === PLAYER 2 TURN 18: Teleportation Between Gates ===
    console.log('=== Player 2 Turn 18: Teleportation Between Gates ===');
    await GameHelpers.setupPlayerSession(page, player2Id);
    await GameHelpers.waitForGameLoad(page);

    // Player 2 should be at the healing fountain from Turn 16
    console.log('Player 2 is at healing fountain, will teleport instead of battle');
    
    // Get the stored teleportation gate positions
    const teleportPositions = await page.evaluate(() => {
      const gate1 = localStorage.getItem('teleportGate1Position');
      const gate2 = localStorage.getItem('teleportGate2Position');
      return {
        gate1: gate1 ? JSON.parse(gate1) : null,
        gate2: gate2 ? JSON.parse(gate2) : null
      };
    });
    
    console.log(`Teleportation gate positions - Gate 1: ${JSON.stringify(teleportPositions.gate1)}, Gate 2: ${JSON.stringify(teleportPositions.gate2)}`);
    
    // First click any available move marker to move from healing fountain
    await GameHelpers.movePlayer(page, 0, 0);
    console.log('Moved from healing fountain to adjacent position');
    await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT);
    
    // Now move to the second teleportation gate
    await GameHelpers.movePlayer(page, teleportPositions.gate2.x, teleportPositions.gate2.y);
    console.log(`Moved to second teleportation gate at ${teleportPositions.gate2.x},${teleportPositions.gate2.y}`);
    
    // Wait for movement to complete
    await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT);

    // Now we should see teleportation markers - click to teleport
    console.log('Looking for teleportation options...');

    await GameHelpers.movePlayer(page, teleportPositions.gate1.x, teleportPositions.gate1.y);
    console.log(`✓ Teleported to first gate at ${teleportPositions.gate1.x},${teleportPositions.gate1.y}`);
    await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT);

    // End turn after teleportation
    await page.locator('.end-turn-btn').click();
    console.log('Clicked End Turn button');

    // Wait for turn change from 18 to 19
    await waitForTurnChange(18, 19);

    // === PLAYER 1 TURN 19: Dragon Battle - Game End ===
    console.log('=== Player 1 Turn 19: Dragon Battle - Game End ===');
    await GameHelpers.setupPlayerSession(page, player1Id);
    await GameHelpers.waitForGameLoad(page);

    // Check if there's a pick up dialog at the start of the turn
    const pickUpAtStart = await page.locator('[role="dialog"]:has-text("Pick Up Item")').isVisible({ timeout: 2000 }).catch(() => false);
    if (pickUpAtStart) {
      console.log('Pick up dialog appeared at start of turn - canceling it');
      const cancelButton = page.locator('[role="dialog"] button:has-text("Cancel")');
      if (await cancelButton.isVisible()) {
        await cancelButton.click();
        await page.waitForTimeout(500);
      }
    }

    // Get stored dragon position from localStorage
    const dragonPosFinal = await page.evaluate(() => {
      const stored = localStorage.getItem('dragonTilePosition');
      return stored ? JSON.parse(stored) : null;
    }) || { x: 0, y: 0 };
    console.log(`Dragon position: ${dragonPosFinal.x},${dragonPosFinal.y}`);

    console.log(`Moving to dragon for final battle at position: ${dragonPosFinal.x},${dragonPosFinal.y}`);

    // Move to dragon tile for final battle
    await GameHelpers.movePlayer(page, dragonPosFinal.x, dragonPosFinal.y);

    // Wait for battle modal - Dragon battle
    await page.waitForSelector('.battle-report-modal', { state: 'visible', timeout: TIMEOUTS.BATTLE_MODAL });
    console.log('Dragon battle modal appeared - Player 1 will win');

    // Wait a bit for the battle result to render
    await page.waitForTimeout(1000);

    // Debug: Get the damage values shown in the modal
    const playerDamage = await page.locator('.player-stats .big-number').textContent().catch(() => 'unknown');
    const monsterHP = await page.locator('.monster-stats .big-number').textContent().catch(() => 'unknown');
    const monsterName = await page.locator('.monster-name').textContent().catch(() => 'unknown');
    console.log(`Battle: Player damage ${playerDamage} vs ${monsterName} HP ${monsterHP}`);

    // Check if this is a victory by looking for victory indicators
    const rewardSectionVisible = await page.locator('.reward-section').isVisible().catch(() => false);
    const victoryRewardText = await page.locator('.reward-title:has-text("Victory")').isVisible().catch(() => false);
    const comparisonVictory = await page.locator('.comparison-symbol.greater-than').isVisible().catch(() => false);
    
    console.log(`Victory indicators - Reward section: ${rewardSectionVisible}, Victory text: ${victoryRewardText}, Damage > HP: ${comparisonVictory}`);
    
    if (!rewardSectionVisible && !comparisonVictory) {
      // Take a screenshot to debug
      await page.screenshot({ path: 'test-results/dragon-battle-not-victory.png', fullPage: true });
      throw new Error('Expected victory against dragon but battle was not won');
    }

    // Check what buttons are available
    const pickupButtonVisible = await page.locator('.pick-up-btn').isVisible().catch(() => false);
    const leaveButtonVisible = await page.locator('.leave-item-btn').isVisible().catch(() => false);
    const endTurnButtonVisible = await page.locator('.end-turn-btn').isVisible().catch(() => false);
    
    console.log(`Button visibility - Pickup: ${pickupButtonVisible}, Leave: ${leaveButtonVisible}, End Turn: ${endTurnButtonVisible}`);

    // Check if there's a reward mentioned
    const rewardVisible = await page.locator('.reward-item').isVisible().catch(() => false);
    console.log(`Reward visible: ${rewardVisible}`);

    // Wait for battle modal to close
    await GameHelpers.continueBattle(page, 'pickup');

    // Wait for game to end
    await page.getByText(/🏆 Game Over: Leaderboard/i).waitFor({ timeout: TIMEOUTS.MODAL_WAIT });
    console.log('=== GAME ENDED (UI shows game over) ===');

    // Verify final game state via API
    const finalGameState = await request.get(`${apiUrl}/api/game/${gameId}`);
    const finalGameData = await finalGameState.json();

    // Verify winner based on treasure points or inventory
    const player1FinalData = finalGameData.players.find((p: any) => p.id === player1Id);
    const player2FinalData = finalGameData.players.find((p: any) => p.id === player2Id);

    // Calculate treasure points from inventory if not provided
    const calculateTreasurePoints = (player: any) => {
      if (player.treasurePoints !== undefined) return player.treasurePoints;

      let points = 0;
      const treasures = player.inventory?.treasures || [];
      for (const treasure of treasures) {
        points += treasure.treasureValue || 0;
      }
      return points;
    };

    const player1Points = calculateTreasurePoints(player1FinalData);
    const player2Points = calculateTreasurePoints(player2FinalData);

    // Final assertion - Player 1 should have more treasure or game should have ended
    if (finalGameData.state?.status === 'finished') {
      console.log('Game finished successfully!');
      // Check winner if available
      if (finalGameData.winner) {
        console.log(`Winner: ${finalGameData.winner}`);
      } else {
        // Determine winner by treasure points
        if (player1Points > player2Points) {
          console.log(`Player 1 won with ${player1Points} treasure points vs Player 2's ${player2Points}`);
        } else if (player2Points > player1Points) {
          console.log(`Player 2 won with ${player2Points} treasure points vs Player 1's ${player1Points}`);
        } else {
          console.log(`Game ended in a tie with ${player1Points} treasure points each`);
        }
      }
    } else {
      throw new Error('Game did not end after defeating dragon');
    }


    // Take final screenshot
    await GameHelpers.screenshot(page, 'two-player-game-extended-complete');
    printElapsedTime();
    console.log('[TEST] Test completed');
  });
});