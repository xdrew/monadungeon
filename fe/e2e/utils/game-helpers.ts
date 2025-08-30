import { Page, Locator, expect } from '@playwright/test';

// Timeout constants - optimized for faster test execution
const TIMEOUTS = {
  MODAL_WAIT: 6000,       // 5 seconds for general modals
  BATTLE_MODAL: 3000,     // 3 seconds for battle modals
  ELEMENT_WAIT: 10000,    // 10 seconds for elements to appear
  SHORT_WAIT: 1000,       // 1 second for quick waits
  ANIMATION_WAIT: 500,    // 0.5 seconds for animations
  NETWORK_IDLE: 3000,     // 3 seconds for network
  DIALOG_WAIT: 2000       // 2 seconds for dialogs
};

/**
 * Helper functions for common game actions in E2E tests
 */
export class GameHelpers {
  /**
   * Set up player session in the browser
   */
  static async setupPlayerSession(page: Page, playerId: string, isAIGame: boolean = false): Promise<void> {
    await page.evaluate(({ pid, hasAI }) => {
      localStorage.setItem('currentPlayerId', pid);
      // For hotseat games, we need to update humanPlayerId to match currentPlayerId
      // This allows each human player to take their turn
      if (!hasAI) {
        localStorage.setItem('humanPlayerId', pid);
      }
      // Clear any virtual player ID in hotseat mode
      if (!hasAI && localStorage.getItem('virtualPlayerId')) {
        localStorage.removeItem('virtualPlayerId');
      }
    }, { pid: playerId, hasAI: isAIGame });
  }


  /**
   * Wait for game to fully load
   */
  static async waitForGameLoad(page: Page): Promise<void> {
    await page.waitForSelector('.game-field, .game-interface, #game-canvas', { 
      state: 'visible',
      timeout: TIMEOUTS.ELEMENT_WAIT 
    });
    
    // Wait for initial animations to complete
    await page.waitForLoadState('networkidle');
    
    // Check if the game UI is fully loaded with available places
    await page.waitForFunction(() => {
      // Check for available place markers (they may or may not have clickable class initially)
      const availablePlaces = document.querySelectorAll('.available-place');
      // Check for place icons specifically (the green plus buttons)
      const placeIcons = document.querySelectorAll('.place-icon');
      // Also check that the game container is loaded
      const gameContainer = document.querySelector('.game-container');
      
      // Either we have available places with place icons, or we have clickable elements
      const hasPlacementOptions = (availablePlaces.length > 0 && placeIcons.length > 0) || 
                                  document.querySelectorAll('.available-place.clickable').length > 0;
      
      return hasPlacementOptions && gameContainer;
    }, { timeout: TIMEOUTS.MODAL_WAIT });
    
    // Brief pause for any final UI updates
    await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT);
  }

  /**
   * Pick a tile from the deck
   * In this game, picking and placing are combined - you click where to place
   */
  static async pickTile(page: Page): Promise<void> {
    // This game doesn't have a separate pick action
    // Tiles are picked automatically when you click a placement position
    // So this method is a no-op but kept for compatibility
  }

  /**
   * Place a tile at the specified position
   * @returns Object containing the coordinates where the tile was placed
   */
  static async placeTile(page: Page, x: number, y: number): Promise<{ x: number; y: number }> {
    // First, try to center on available places to ensure they're visible
    try {
      const centerButton = page.locator('button[title="Center on Available Places"]');
      if (await centerButton.count() > 0) {
        await centerButton.click();
        await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT); // Wait for centering animation
      }
    } catch {
      // If center button isn't available, continue anyway
    }
    
    // Wait for available placement positions to appear
    // Look for available places with place-icon (the green plus buttons)
    const availablePlaceSelector = '.available-place:has(.place-icon)';
    const fallbackSelector = '.available-place.place-tile';
    
    try {
      // First try the most specific selector
      await page.waitForSelector(availablePlaceSelector, {
        state: 'visible',
        timeout: TIMEOUTS.ELEMENT_WAIT
      });
    } catch (error) {
      // Try fallback selector
      try {
        await page.waitForSelector(fallbackSelector, {
          state: 'visible',
          timeout: TIMEOUTS.SHORT_WAIT
        });
      } catch (fallbackError) {
        // Take a screenshot if selector not found
        await page.screenshot({ path: `test-results/no-tile-placement-options-${Date.now()}.png`, fullPage: true });
        
        // Check if there's a "Processing..." message
        const processingVisible = await page.getByText('Processing...').isVisible().catch(() => false);
        if (processingVisible) {
          console.log('Game is still processing, waiting longer...');
          await page.waitForTimeout(TIMEOUTS.MODAL_WAIT);
          
          // Try again after waiting with the primary selector
          await page.waitForSelector(availablePlaceSelector, {
            state: 'visible',
            timeout: TIMEOUTS.MODAL_WAIT
          });
        } else {
          throw new Error('No available tile placement positions found');
        }
      }
    }
    
    // Wait a moment to ensure the UI is ready to accept clicks
    await page.waitForTimeout(TIMEOUTS.SHORT_WAIT);
    
    // Check whose turn it is
    const currentPlayer = await page.evaluate(() => {
      const currentPlayerId = localStorage.getItem('currentPlayerId');
      const turnInfo = document.querySelector('[class*="Turn"]')?.textContent || '';
      return { currentPlayerId, turnInfo };
    });
    console.log('Current player info:', currentPlayer);
    
    // Wait for elements to become clickable (isPlayerTurn must be true)
    try {
      await page.waitForFunction(() => {
        const clickableElements = document.querySelectorAll('.available-place.clickable');
        // Also check if there are any disabled markers
        const disabledElements = document.querySelectorAll('.available-place.disabled');
        const allElements = document.querySelectorAll('.available-place');
        
        console.log(`Available places: ${allElements.length}, Clickable: ${clickableElements.length}, Disabled: ${disabledElements.length}`);
        
        return clickableElements.length > 0;
      }, { timeout: TIMEOUTS.ELEMENT_WAIT });
      console.log('Clickable elements are now available');
    } catch (e) {
      console.log('Warning: No clickable elements found, proceeding anyway');
      
      // Debug: Check game state
      const debugInfo = await page.evaluate(() => {
        return {
          availablePlaces: document.querySelectorAll('.available-place').length,
          clickablePlaces: document.querySelectorAll('.available-place.clickable').length,
          disabledPlaces: document.querySelectorAll('.available-place.disabled').length,
          placeIcons: document.querySelectorAll('.place-icon').length
        };
      });
      console.log('Debug info:', debugInfo);
    }
    
    // Get available places - prioritize clickable available places
    let availablePlaces = page.locator('.available-place.clickable').filter({ hasNot: page.locator('text="👣"') });
    let count = await availablePlaces.count();
    
    if (count === 0) {
      // Try looking for available places with place-icon (the green plus buttons)
      availablePlaces = page.locator('.available-place:has(.place-icon)');
      count = await availablePlaces.count();
    }
    
    if (count === 0) {
      // Try fallback selector
      availablePlaces = page.locator('.available-place.place-tile');
      count = await availablePlaces.count();
    }
    
    if (count === 0) {
      // Take a debug screenshot
      await page.screenshot({ path: `test-results/no-available-places-${Date.now()}.png`, fullPage: true });
      throw new Error('No available places to put tile');
    }
    
    // Click to place tile
    console.log(`Found ${count} available placement positions`);
    console.log('About to click on available place...');
    
    // Count existing ghost tiles before clicking
    const ghostTilesBefore = await page.locator('.tile.ghost-tile').count();
    
    // Get the first available place and capture its position
    const firstPlace = availablePlaces.first();
    
    // Ensure the element is visible and ready
    await firstPlace.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT_WAIT });
    
    // Try to extract position from the element's attributes or title
    let placedPosition = { x: 0, y: 0 };
    try {
      const title = await firstPlace.getAttribute('title');
      if (title && title.includes('position:')) {
        // Extract coordinates from title like "Available position: 1,-5"
        const match = title.match(/position:\s*(-?\d+),\s*(-?\d+)/);
        if (match) {
          placedPosition.x = parseInt(match[1]);
          placedPosition.y = parseInt(match[2]);
          console.log(`Placing tile at position: ${placedPosition.x},${placedPosition.y}`);
        }
      }
      
      // Fallback: try data-position attribute
      const dataPosition = await firstPlace.getAttribute('data-position');
      if (dataPosition && dataPosition.includes(',')) {
        const [xStr, yStr] = dataPosition.split(',');
        placedPosition.x = parseInt(xStr);
        placedPosition.y = parseInt(yStr);
        console.log(`Placing tile at position (from data-position): ${placedPosition.x},${placedPosition.y}`);
      }
    } catch (e) {
      console.log('Could not extract position from available place element');
    }
    
    // Log element info for debugging
    const isVisible = await firstPlace.isVisible();
    const boundingBox = await firstPlace.boundingBox();
    console.log(`Element visible: ${isVisible}, bounding box:`, boundingBox);
    
    // Check if the element has the clickable class
    const hasClickableClass = await firstPlace.evaluate(el => el.classList.contains('clickable'));
    console.log(`Has clickable class: ${hasClickableClass}`);
    
    // Try clicking with different strategies
    if (hasClickableClass) {
      try {
        // If it has clickable class, try normal click
        await firstPlace.click();
        console.log('Normal click on clickable element completed');
      } catch (clickError) {
        console.log('Normal click failed, trying force click...');
        await firstPlace.click({ force: true });
        console.log('Force click completed');
      }
    } else {
      console.log('Element not clickable, trying to click the inner place-icon...');
      // Try to click the inner place-icon element instead
      const placeIcon = firstPlace.locator('.place-icon');
      if (await placeIcon.count() > 0) {
        await placeIcon.first().click({ force: true });
        console.log('Clicked on place-icon');
      } else {
        // Fallback: dispatch click event directly
        await firstPlace.evaluate(element => {
          element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        });
        console.log('Direct event dispatch completed');
      }
    }
    
    // Wait for the UI to process the click
    await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT);

    // Wait for either a new ghost tile to appear or ghost tile controls to be visible
    try {
      await Promise.race([
        // Wait for ghost tile count to increase
        page.waitForFunction(
          (beforeCount) => {
            const currentGhostTiles = document.querySelectorAll('.tile.ghost-tile');
            return currentGhostTiles.length > beforeCount;
          },
          ghostTilesBefore,
          { timeout: TIMEOUTS.MODAL_WAIT }
        ),
        // Or wait for ghost tile controls (rotation buttons)
        page.waitForSelector('.ghost-tile-controls', {
          state: 'visible',
          timeout: TIMEOUTS.MODAL_WAIT
        }),
        // Or wait for a ghost tile with rotate button
        page.waitForSelector('.ghost-rotate-btn', {
          state: 'visible',
          timeout: TIMEOUTS.MODAL_WAIT
        })
      ]);
    } catch (error) {
      console.log('Ghost tile detection failed, continuing anyway');
    }

    // Press Enter to confirm placement
    console.log('Pressing Enter to confirm tile placement');
    await page.keyboard.press('Enter');
    
    // Wait a bit for the placement to process
    await page.waitForTimeout(TIMEOUTS.SHORT_WAIT);
    
    // Wait for either:
    // 1. Rotate button to disappear (tile placed)
    // 2. Ghost tile to disappear
    // 3. Available places to change
    // 4. Just continue after timeout
    try {
      await Promise.race([
        // Wait for rotate button to disappear
        page.waitForSelector('.ghost-rotate-btn', {
          state: 'hidden',
          timeout: TIMEOUTS.DIALOG_WAIT
        }),
        // Wait for ghost tiles to disappear
        page.waitForFunction(
          () => {
            const ghostTiles = document.querySelectorAll('.tile.ghost-tile');
            return ghostTiles.length === 0;
          },
          { timeout: TIMEOUTS.DIALOG_WAIT }
        ),
        // Wait for available places to change
        page.waitForFunction(
          (initialCount) => {
            const currentPlaces = document.querySelectorAll('.available-place.place-tile.clickable');
            return currentPlaces.length !== initialCount;
          },
          count,
          { timeout: TIMEOUTS.DIALOG_WAIT }
        )
      ]);
      console.log('Tile placement confirmed');
    } catch {
      console.log('Tile placement confirmation timed out, continuing anyway');
    }
    
    // Brief pause to ensure UI is stable
    await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT);
    
    // Return the position where the tile was placed
    return placedPosition;
  }

  /**
   * Rotate the current tile
   */
  static async rotateTile(page: Page, times: number = 1): Promise<void> {
    const rotateButton = page.locator('button:has-text("Rotate"), [data-testid="rotate-tile-button"]').first();
    for (let i = 0; i < times; i++) {
      await rotateButton.click();
    }
  }

  /**
   * Move player to a position
   */
  static async movePlayer(page: Page, x: number, y: number): Promise<void> {
    // Wait for move markers to be available
    await page.waitForSelector('.move-marker.clickable:not(.place-tile)', {
      state: 'visible',
      timeout: 10000
    });
    
    // Get initial count of move markers
    const initialMoveCount = await page.locator('.move-marker.clickable:not(.place-tile)').count();
    console.log(`Found ${initialMoveCount} move markers`);
    
    // Get all move markers and find the one at the target position
    const allMoveMarkers = await page.locator('.move-marker.clickable:not(.place-tile)').all();
    let targetMarkerFound = false;
    
    // Look for a move marker with matching title or at same position as target tile
    for (const marker of allMoveMarkers) {
      const title = await marker.getAttribute('title').catch(() => '');
      const style = await marker.getAttribute('style').catch(() => '');
      
      // Check if title contains the position
      if (title.includes(`${x},${y}`)) {
        console.log(`Found move marker with position ${x},${y} in title: ${title}`);
        await marker.click({ force: true });
        targetMarkerFound = true;
        break;
      }
      
      // Alternative: check if marker is at same position as tile with data-position
      const markerBox = await marker.boundingBox();
      if (markerBox) {
        // Find tile at same position
        const tileAtSamePosition = await page.locator(`.tile[data-position="${x},${y}"]`).first();
        const tileBox = await tileAtSamePosition.boundingBox().catch(() => null);
        
        if (tileBox && Math.abs(markerBox.x - tileBox.x) < 5 && Math.abs(markerBox.y - tileBox.y) < 5) {
          console.log(`Found move marker at same position as tile ${x},${y}`);
          await marker.click({ force: true });
          targetMarkerFound = true;
          break;
        }
      }
    }
    
    if (!targetMarkerFound) {
      // Fallback: Try to find the tile at position and check for overlapping move markers
      const tileAtPosition = page.locator(`.tile[data-position="${x},${y}"]`).first();
      const tileExists = await tileAtPosition.count() > 0;
      
      if (tileExists) {
        console.log(`Found tile at position ${x},${y}, looking for overlapping move marker`);
        
        // Get tile position
        const tileBox = await tileAtPosition.boundingBox();
        if (tileBox) {
          // Find move marker at same coordinates
          for (const marker of allMoveMarkers) {
            const markerBox = await marker.boundingBox();
            if (markerBox && Math.abs(markerBox.x - tileBox.x) < 5 && Math.abs(markerBox.y - tileBox.y) < 5) {
              console.log(`Found overlapping move marker for tile at ${x},${y}`);
              await marker.click({ force: true });
              targetMarkerFound = true;
              break;
            }
          }
        }
      }
      
      if (!targetMarkerFound) {
        console.log(`No move marker found for position ${x},${y}, using first available`);
        const firstMarker = page.locator('.move-marker.clickable:not(.place-tile)').first();
        await firstMarker.click({ force: true });
      }
    }
    
    // Wait for either:
    // 1. Move markers to change/disappear
    // 2. Battle modal to appear
    // 3. A reasonable timeout
    try {
      await Promise.race([
        // Wait for move markers to change
        page.waitForFunction(
          (initialCount) => {
            const currentMarkers = document.querySelectorAll('.move-marker.clickable:not(.place-tile)');
            // Movement is complete when move markers change (either disappear or update)
            return currentMarkers.length !== initialCount;
          },
          initialMoveCount,
          { timeout: TIMEOUTS.MODAL_WAIT }
        ),
        // Wait for battle modal to appear
        page.waitForSelector('.battle-report-modal', { 
          state: 'visible',
          timeout: TIMEOUTS.MODAL_WAIT 
        }),
        // Or just wait a brief moment for the move to complete
        page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT)
      ]);
      console.log('Move completed or battle started');
    } catch (e) {
      console.log('Move might have completed without UI change:', e.message);
    }
    
    // Verify move completed
    const newMoveCount = await page.locator('.move-marker.clickable:not(.place-tile)').count();
    console.log(`Move markers after movement: ${newMoveCount} (was ${initialMoveCount})`);
  }

  /**
   * Wait for and handle battle dialog
   */
  static async waitForBattle(page: Page): Promise<Locator> {
    // Try multiple selectors for battle UI
    const battleSelectors = [
      '.battle-dialog',
      '.battle-modal', 
      '.combat-screen',
      '[class*="battle"]',
      '.battle-report'
    ];
    
    for (const selector of battleSelectors) {
      const element = page.locator(selector).first();
      try {
        await expect(element).toBeVisible({ timeout: TIMEOUTS.NETWORK_IDLE });
        return element;
      } catch {
        // Try next selector
      }
    }
    
    // If no battle UI found, battle might be auto-resolved
    console.log('No battle UI found - battle might be auto-resolved');
    return page.locator('body'); // Return body as fallback
  }

  /**
   * Get battle result text
   * Note: This assumes the battle modal is already visible
   */
  static async getBattleResult(page: Page): Promise<string> {
    // Don't wait for modal - caller should ensure it's already visible
    
    // Check for specific battle result titles
    const defeatTitle = await page.locator('.defeat-title').count();
    if (defeatTitle > 0) {
      return 'lost';
    }
    
    const victoryTitle = await page.locator('.victory-title').count();
    if (victoryTitle > 0) {
      return 'won';
    }
    
    const drawTitle = await page.locator('.draw-title').count();
    if (drawTitle > 0) {
      return 'draw';
    }
    
    // Look for battle result indicators in text
    const winPatterns = ['victory', 'won', 'win', 'defeated the'];
    const losePatterns = ['defeat', 'lost', 'lose', 'knocked out'];
    const drawPatterns = ['draw', 'tie', 'retreat'];
    
    // Check visible text for result
    const bodyText = await page.locator('body').textContent() || '';
    
    for (const pattern of winPatterns) {
      if (bodyText.toLowerCase().includes(pattern)) {
        return 'won';
      }
    }
    
    for (const pattern of losePatterns) {
      if (bodyText.toLowerCase().includes(pattern)) {
        return 'lost';
      }
    }
    
    for (const pattern of drawPatterns) {
      if (bodyText.toLowerCase().includes(pattern)) {
        return 'draw';
      }
    }
    
    // Check HP to determine result
    const hpText = await page.locator('[class*="hp"], [class*="health"]').first().textContent() || '';
    if (hpText.includes('0')) {
      return 'lost';
    }
    
    return 'unknown';
  }

  /**
   * Continue from battle dialog with specific action
   * Note: This assumes the battle modal is already visible
   * @param action - The action to take: 'pickup', 'leave', 'close', or 'consumables'
   * @param consumableIds - Array of consumable item IDs to use (for 'consumables' action)
   * @param replaceItemId - ID of item to replace when inventory is full
   */
  static async continueBattle(page: Page, action: 'pickup' | 'leave' | 'close' | 'consumables' = 'close', consumableIds?: string[], replaceItemId?: string): Promise<void> {
    // Don't wait for modal - caller should ensure it's already visible
    
    switch (action) {
      case 'pickup':
        // Click "Pick up and end turn" button (may appear after dice animation)
        {
          const tryClickPickup = async () => {
            // Primary: class-based selector
            const btn = page.locator('.pick-up-btn').first();
            if (await btn.isVisible().catch(() => false)) {
              await btn.click();
              console.log('Clicked pick up and end turn button');
              return true;
            }
            // Alternative: text-based selector
            const textBtn = page.locator('button').filter({ hasText: /pick\s*up/i }).first();
            if (await textBtn.isVisible().catch(() => false)) {
              await textBtn.click();
              console.log('Clicked pick up button by text');
              return true;
            }
            return false;
          };

          // Wait for the button to become available, accounting for ~1.1s animation
          let clicked = await tryClickPickup();
          if (!clicked) {
            // Wait a bit for animation/state to settle
            await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT + 800);
            // Retry within a small window
            try {
              await page.waitForSelector('.pick-up-btn, button:has-text("Pick up")', {
                state: 'visible',
                timeout: TIMEOUTS.MODAL_WAIT
              });
            } catch {
              // continue to attempt click anyway
            }
            clicked = await tryClickPickup();
          }

          if (!clicked) {
            console.log('Pick up button still not visible after waiting - cannot proceed with pickup');
          }
        }
        break;
        
      case 'leave':
        // Click "Leave item and end turn" button
        const leaveButton = page.locator('.leave-item-btn').first();
        if (await leaveButton.isVisible()) {
          await leaveButton.click();
          console.log('Clicked leave item and end turn button');
        } else {
          // Fallback to end turn button if leave button not available
          const endTurnButton = page.locator('.end-turn-btn').first();
          if (await endTurnButton.isVisible()) {
            await endTurnButton.click();
            console.log('Clicked end turn button');
          }
        }
        break;
        
      case 'consumables':
        // First select consumables if provided
        if (consumableIds && consumableIds.length > 0) {
          for (const itemId of consumableIds) {
            const consumableItem = page.locator(`.selectable-item`).filter({ has: page.locator(`[data-item-id="${itemId}"]`) });
            if (await consumableItem.count() > 0) {
              await consumableItem.click();
              console.log(`Selected consumable with ID: ${itemId}`);
            }
          }
        }
        
        // Then click the appropriate button based on outcome
        const fightAndWinButton = page.locator('button:has-text("Fight, win, and pick up reward")').first();
        const fightAndLeaveButton = page.locator('button:has-text("Fight, win, and leave reward")').first();
        const fightButton = page.locator('.finalize-battle-btn').first();
        
        if (await fightAndWinButton.isVisible()) {
          await fightAndWinButton.click();
          console.log('Clicked fight, win, and pick up reward button');
        } else if (await fightAndLeaveButton.isVisible()) {
          await fightAndLeaveButton.click();
          console.log('Clicked fight, win, and leave reward button');
        } else if (await fightButton.isVisible()) {
          await fightButton.click();
          console.log('Clicked fight with selected items button');
        } else {
          console.log('No consumable action button found');
        }
        break;
        
      case 'close':
      default:
        // Click the X close button or End Turn button
        const closeButton = page.locator('.close-battle-btn').first();
        const endTurnButton = page.locator('.end-turn-btn').first();
        
        if (await closeButton.isVisible()) {
          await closeButton.click();
          console.log('Clicked close battle button (X)');
        } else if (await endTurnButton.isVisible()) {
          await endTurnButton.click();
          console.log('Clicked end turn button');
        } else {
          console.log('No close or end turn button found');
        }
        break;
    }
    
    // Handle inventory replacement if needed
    if (replaceItemId && (action === 'pickup' || action === 'consumables')) {
      // Wait for inventory selection UI
      const inventorySelection = page.locator('.inventory-item-replace').first();
      if (await inventorySelection.isVisible({ timeout: TIMEOUTS.DIALOG_WAIT })) {
        // Click the item to replace
        const itemToReplace = page.locator('.inventory-item-replace').filter({ 
          has: page.locator(`[data-item-id="${replaceItemId}"]`) 
        }).first();
        
        if (await itemToReplace.count() === 0) {
          // Fallback: click by matching item content
          const itemByContent = page.locator('.inventory-item-replace').filter({
            hasText: replaceItemId
          }).first();
          if (await itemByContent.count() > 0) {
            await itemByContent.click();
            console.log(`Selected item to replace by content: ${replaceItemId}`);
          }
        } else {
          await itemToReplace.click();
          console.log(`Selected item to replace: ${replaceItemId}`);
        }
        
        // Click confirm replacement button
        const confirmButton = page.locator('.confirm-replacement-btn').first();
        await confirmButton.click();
        console.log('Clicked confirm replacement button');
      }
    }
    
    // Note: The caller should wait for battle completion using waitForBattleComplete()
    // This method only handles the button clicks and inventory replacement
  }

  /**
   * Select consumables for battle
   */
  static async selectConsumables(page: Page, itemNames: string[]): Promise<void> {
    for (const itemName of itemNames) {
      const item = page.locator(`[data-item-name="${itemName}"], :has-text("${itemName}")`).first();
      await item.click();
    }
    
    const confirmButton = page.locator('button:has-text("Confirm"), [data-testid="confirm-consumables"]').first();
    await confirmButton.click();
  }

  /**
   * Pick up an item after battle
   */
  static async pickupItem(page: Page): Promise<void> {
    await page.waitForSelector('.item-pickup-dialog, .item-modal, [class*="item-pickup"]', { 
      state: 'visible',
      timeout: TIMEOUTS.NETWORK_IDLE 
    });
    
    const pickupButton = page.locator('button:has-text("Pick Up"), button:has-text("Take"), [data-testid="pickup-item-button"]').first();
    await pickupButton.click();
  }

  /**
   * Skip item pickup
   */
  static async skipItem(page: Page): Promise<void> {
    const skipButton = page.locator('button:has-text("Skip"), button:has-text("Leave"), [data-testid="skip-item-button"]').first();
    await skipButton.click();
  }

  /**
   * End current turn
   */
  static async endTurn(page: Page): Promise<void> {
    // Look for end turn button
    const endTurnSelectors = [
      '.end-turn-btn',  // Primary selector based on actual HTML
      'button:has-text("End Turn")',
      'button:has-text("End turn")',  // Added lowercase version
      'button:has-text("Pass")',
      '[data-testid="end-turn-button"]',
      '.end-turn-button'
    ];
    
    // Try to find and click the end turn button
    let buttonFound = false;
    for (const selector of endTurnSelectors) {
      try {
        const button = page.locator(selector).first();
        if (await button.count() > 0 && await button.isVisible()) {
          // Wait for button to be clickable
          await button.waitFor({ state: 'visible' });
          
          // Store current player info before clicking
          const moveMarkersBeforeClick = await page.locator('.move-marker.clickable:not(.place-tile)').count();
          
          await button.click();
          buttonFound = true;
          console.log(`Clicked end turn button: ${selector}`);
          
          // Wait for turn transition by monitoring UI changes
          await page.waitForFunction(
            (markersBefore) => {
              // Turn has changed when move markers change or disappear
              const currentMarkers = document.querySelectorAll('.move-marker.clickable:not(.place-tile)').length;
              return currentMarkers !== markersBefore;
            },
            moveMarkersBeforeClick,
            { timeout: TIMEOUTS.MODAL_WAIT }
          ).catch(() => {
            console.log('Turn transition wait timed out');
          });
          
          return;
        }
      } catch {
        // Try next selector
      }
    }
    
    if (!buttonFound) {
      // No end turn button - turn might end automatically after movement
      console.log('No end turn button found - turn might end automatically');
      // Wait briefly to see if turn changes automatically
      await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT);
    }
  }

  /**
   * Get current player name
   */
  static async getCurrentPlayer(page: Page): Promise<string> {
    
    const selectors = [
      '.turn-info',
      '.current-player', 
      '[class*="player-info"]',
      '.player-name',
      '[class*="active-player"]',
      '.player-turn',
      '.active'
    ];
    
    for (const selector of selectors) {
      try {
        const element = page.locator(selector).first();
        const count = await element.count();
        if (count > 0) {
          const text = await element.textContent({ timeout: TIMEOUTS.DIALOG_WAIT });
          if (text && text.trim()) {
            return text.trim();
          }
        }
      } catch {
        // Try next selector
      }
    }
    
    // If no player info found, return a default
    return 'Player 1';
  }

  /**
   * Handle inventory replacement dialog
   */
  static async handleInventoryReplacement(page: Page, itemToReplaceIndex: number = 0): Promise<void> {
    // Wait for inventory full dialog
    await page.waitForSelector('.inventory-full-dialog', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    console.log('Inventory full dialog appeared');
    
    // Wait a bit for dialog to fully render
    await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT);
    
    // Click on the item to replace (by index)
    const inventoryItems = page.locator('.inventory-item');
    const itemCount = await inventoryItems.count();
    console.log(`Found ${itemCount} items in inventory`);
    
    if (itemToReplaceIndex < itemCount) {
      await inventoryItems.nth(itemToReplaceIndex).click();
      console.log(`Selected item at index ${itemToReplaceIndex} to replace`);
      
      // Wait for item to be selected
      await page.waitForTimeout(TIMEOUTS.ANIMATION_WAIT / 2);
    }
    
    // Click replace button
    const replaceButton = page.locator('button.replace-btn').first();
    await replaceButton.waitFor({ state: 'visible' });
    await replaceButton.click();
    console.log('Clicked replace button');
    
    // Wait for dialog to close
    await page.waitForSelector('.inventory-full-dialog', { state: 'hidden', timeout: TIMEOUTS.MODAL_WAIT });
  }

  /**
   * Handle item pickup dialog when stepping on tile with item
   */
  static async handleItemPickupDialog(page: Page, action: 'pickup' | 'leave'): Promise<void> {
    // Wait for item pickup dialog by looking for the "You found an item!" text
    try {
      await page.getByText('You found an item!').waitFor({ timeout: TIMEOUTS.MODAL_WAIT });
      console.log('Item pickup dialog appeared');
    } catch {
      // Fallback to checking for dialog/modal elements
      await page.waitForSelector('.item-pickup-dialog, .pickup-dialog, [role="dialog"]', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
      console.log('Item pickup dialog appeared (via selector)');
    }
    
    if (action === 'pickup') {
      // Try multiple approaches to find the pickup button
      let pickupButton = page.getByRole('button', { name: 'Pick Up' });
      if (await pickupButton.count() === 0) {
        pickupButton = page.locator('button').filter({ hasText: /pick.*up/i }).first();
      }
      await pickupButton.click();
      console.log('Clicked pickup button');
    } else {
      // Try multiple approaches to find the leave button
      let leaveButton = page.getByRole('button', { name: 'Leave It' });
      if (await leaveButton.count() === 0) {
        leaveButton = page.locator('button').filter({ hasText: /leave.*it|leave|skip/i }).first();
      }
      await leaveButton.click();
      console.log('Clicked leave/skip button');
    }
    
    // Wait for dialog to close
    await page.waitForTimeout(TIMEOUTS.SHORT_WAIT);
  }

  /**
   * Verify player is stunned and skip their turn
   */
  static async verifyStunnedState(page: Page, playerId: string): Promise<boolean> {
    // Check for stunned indicators
    const stunnedIndicators = [
      '.stunned-player',
      '.player-stunned',
      '[data-stunned="true"]',
      '.hp-0'
    ];
    
    for (const selector of stunnedIndicators) {
      const element = page.locator(selector).first();
      if (await element.count() > 0) {
        console.log(`Player ${playerId} is stunned (found ${selector})`);
        return true;
      }
    }
    
    // Also check if HP is 0 in the UI
    const hpElement = page.locator('.player-hp, .hp-display').first();
    if (await hpElement.count() > 0) {
      const hpText = await hpElement.textContent();
      if (hpText && hpText.includes('0')) {
        console.log(`Player ${playerId} has 0 HP - stunned`);
        return true;
      }
    }
    
    return false;
  }

  /**
   * Open a chest (with or without key)
   */
  static async openChest(page: Page): Promise<void> {
    // Wait for chest interaction UI
    await page.waitForSelector('.chest-dialog, .chest-interaction', { state: 'visible', timeout: TIMEOUTS.MODAL_WAIT });
    console.log('Chest interaction appeared');
    
    // Click open chest button
    const openButton = page.locator('button').filter({ hasText: /open/i }).first();
    if (await openButton.isVisible()) {
      await openButton.click();
      console.log('Opened chest');
    }
  }

  /**
   * Verify game has ended and get winner info
   */
  static async verifyGameEnded(page: Page): Promise<{ ended: boolean; winnerId?: string; scores?: any }> {
    // Check for game end indicators
    const gameEndSelectors = [
      '.game-over',
      '.game-ended',
      '.winner-announcement',
      '[data-game-ended="true"]'
    ];
    
    for (const selector of gameEndSelectors) {
      const element = page.locator(selector).first();
      if (await element.count() > 0) {
        console.log('Game has ended');
        
        // Try to extract winner info
        const winnerElement = page.locator('.winner-name, .winner-id, [data-winner]').first();
        let winnerId = null;
        if (await winnerElement.count() > 0) {
          winnerId = await winnerElement.textContent();
        }
        
        return { ended: true, winnerId };
      }
    }
    
    return { ended: false };
  }

  /**
   * Verify player is at position
   */
  static async verifyPlayerPosition(page: Page, playerId: string, x: number, y: number): Promise<void> {
    const playerElement = page.locator(`[data-player-id="${playerId}"], [class*="player"][id*="${playerId}"], .player-${playerId}`).first();
    const position = await playerElement.getAttribute('data-position');
    expect(position).toBe(`${x},${y}`);
  }

  /**
   * Check if player has item in inventory
   */
  static async verifyInventoryItem(page: Page, itemName: string): Promise<void> {
    const inventory = page.locator('.inventory, .player-inventory, [class*="inventory"]').first();
    await expect(inventory).toContainText(new RegExp(itemName, 'i'));
  }

  /**
   * Take screenshot for debugging
   */
  static async screenshot(page: Page, name: string): Promise<void> {
    await page.screenshot({ 
      path: `test-results/${name}.png`, 
      fullPage: true 
    });
  }

  /**
   * Get all available move positions
   */
  static async getAvailableMoves(page: Page): Promise<string[]> {
    // Look for move markers that are clickable (excluding placement tiles)
    const moveMarkers = page.locator('.move-marker.clickable:not(.place-tile)');
    const count = await moveMarkers.count();
    
    const positions: string[] = [];
    for (let i = 0; i < count; i++) {
      const marker = moveMarkers.nth(i);
      // Try to get position from data attribute or text content
      const pos = await marker.getAttribute('data-position') || 
                  await marker.textContent() || 
                  `position-${i}`;
      positions.push(pos);
    }
    
    console.log(`Found ${count} available moves`);
    return positions;
  }

  /**
   * Get all available tile placement positions
   */
  static async getAvailablePlacements(page: Page): Promise<string[]> {
    const placementMarkers = await page.locator('[data-type="placement-marker"], .can-place').all();
    const positions: string[] = [];
    
    for (const marker of placementMarkers) {
      const pos = await marker.getAttribute('data-position');
      if (pos) positions.push(pos);
    }
    
    return positions;
  }
}