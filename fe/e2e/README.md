# Monadungeon Game E2E Testing with Playwright

This directory contains end-to-end tests for the Monadungeon game using Playwright.

## Handling Backend Randomness

The tests handle backend randomness through several strategies:

### 1. API Mocking
The `GameApiMock` class intercepts API calls and replaces random values with predictable ones:

```typescript
await startGame({
  fixedDiceRolls: [6, 6],        // Control battle dice rolls
  fixedTileSequence: ['Corridor'], // Control which tiles are drawn
  fixedMonsterPositions: {         // Control where monsters appear
    '1,0': true
  },
  fixedItemDrops: {                // Control item rewards
    'Skeleton': 'Axe'
  }
});
```

### 2. Route Interception
Playwright intercepts HTTP requests and modifies responses:
- `/game/start-battle` - Replace dice rolls
- `/game/pick-tile` - Control tile selection
- `/game/move-player` - Force monster encounters

### 3. State Injection
For complex scenarios, inject game state directly:
```typescript
await page.evaluate(() => {
  window.gameStore?.updatePlayerInventory('player-1', {
    weapons: [{ name: 'Sword', damage: 2 }]
  });
});
```

## Test Structure

### Fixtures
- `game-api-mock.ts` - API mocking utilities
- `game-test-base.ts` - Base test with game-specific helpers

### Test Suites
- `battle-turn-flow.spec.ts` - Battle mechanics and turn ending
- `inventory-management.spec.ts` - Item pickup and inventory
- `game-progression.spec.ts` - Full game scenarios

## Running Tests

```bash
# Run all tests
npm run test:e2e

# Run with UI mode (interactive)
npm run test:e2e:ui

# Debug a specific test
npm run test:e2e:debug battle-turn-flow.spec.ts

# Run tests with browser visible
npm run test:e2e:headed

# View test report
npm run test:e2e:report
```

## Writing New Tests

1. Use the `startGame` fixture with mock options:
```typescript
test('my test', async ({ page, startGame }) => {
  await startGame({
    fixedDiceRolls: [3, 4],
    // ... other options
  });
  
  // Your test logic
});
```

2. Use helper functions for common actions:
```typescript
await GameTestHelpers.pickTile(page);
await GameTestHelpers.placeTile(page, 1, 0);
await GameTestHelpers.movePlayer(page, 1, 0);
```

3. Add data-testid attributes to components for reliable selection:
```vue
<button data-testid="pick-tile-button">Pick Tile</button>
```

## Debugging Tips

1. Use `page.pause()` to stop execution and inspect:
```typescript
await page.pause();
```

2. Take screenshots at key points:
```typescript
await page.screenshot({ path: 'debug.png' });
```

3. Enable slow motion to see actions:
```typescript
test.use({ 
  launchOptions: { slowMo: 500 } 
});
```

4. Use verbose logging:
```typescript
DEBUG=pw:api npm run test:e2e
```

## Best Practices

1. **Test User Journeys**: Focus on complete user scenarios rather than individual functions
2. **Use Page Objects**: For complex pages, create page object models
3. **Avoid Hard Waits**: Use Playwright's auto-waiting instead of `waitForTimeout`
4. **Test Error Cases**: Include tests for network failures, validation errors, etc.
5. **Keep Tests Independent**: Each test should set up its own state
6. **Use Descriptive Names**: Test names should explain what's being tested

## Common Patterns

### Waiting for Game State Changes
```typescript
// Wait for turn to change
await page.waitForSelector('[data-current-player="Player 2"]');

// Wait for battle to complete
await page.waitForSelector('.battle-dialog', { state: 'hidden' });

// Wait for API response
await page.waitForResponse('**/game/move-player');
```

### Asserting Game State
```typescript
// Check player position
await GameTestHelpers.assertPlayerPosition(page, 'player-1', 0, 0);

// Check inventory
await GameTestHelpers.assertInventoryItem(page, 'player-1', 'Sword');

// Check turn
await GameTestHelpers.assertTurnEnded(page, 'Player 2');
```

### Handling Dialogs
```typescript
// Battle dialog
await GameTestHelpers.waitForBattle(page);
await page.click('[data-testid="battle-continue-button"]');

// Item pickup
await expect(page.locator('.item-pickup-dialog')).toBeVisible();
await GameTestHelpers.pickupItem(page);
```