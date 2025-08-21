# Testing Strategies for Backend Randomness in Monadungeon

## Overview

The Monadungeon game has several sources of randomness that need to be controlled for predictable testing:

1. **Dice Rolls** - Battle outcomes (2d6)
2. **Tile Drawing** - Which tiles are drawn from the deck
3. **Monster Spawns** - Whether a room has a monster
4. **Item Drops** - What items monsters drop when defeated
5. **Card Shuffling** - Initial deck order

## Strategy 1: API Response Interception (Recommended)

### How it works:
```typescript
// Intercept API calls and modify responses
await page.route('**/game/start-battle', async (route) => {
  const response = await route.fetch();
  const json = await response.json();
  
  // Replace random values with fixed ones
  json.diceResults = [6, 6]; // Always roll 12
  json.totalDamage = 12;
  
  await route.fulfill({ json });
});
```

### Pros:
- No changes to production code
- Tests the actual frontend logic
- Can control specific scenarios precisely

### Cons:
- Requires understanding API response structure
- May break if API changes

## Strategy 2: Backend Test Mode

### How it works:
Add a test mode to your backend that accepts seed values:

```php
// In Battle.php
private function rollDice(): void
{
    if ($this->testMode && isset($this->testDiceRolls)) {
        $this->diceResults = $this->testDiceRolls;
    } else {
        $this->diceResults = [
            random_int(1, 6),
            random_int(1, 6),
        ];
    }
}
```

### Pros:
- More stable than mocking
- Can test backend logic too
- Easier to maintain

### Cons:
- Requires backend changes
- Risk of test code in production

## Strategy 3: Seed-Based Randomness

### How it works:
Use a seeded random number generator:

```typescript
// Start game with specific seed
await page.goto('/game?seed=12345');

// Backend uses seed for all randomness
mt_srand($seed);
```

### Pros:
- Reproducible tests
- No mocking needed
- Works for all random elements

### Cons:
- Requires implementing seed support
- Less control over specific values

## Strategy 4: Probability-Based Testing

### How it works:
Test probabilities rather than specific outcomes:

```typescript
test('monster appears roughly 30% of the time', async () => {
  let monsterCount = 0;
  
  for (let i = 0; i < 100; i++) {
    // Place tile and check for monster
    if (await hasMonster()) {
      monsterCount++;
    }
  }
  
  expect(monsterCount).toBeGreaterThan(20);
  expect(monsterCount).toBeLessThan(40);
});
```

### Pros:
- Tests actual game behavior
- No mocking needed
- Good for balance testing

### Cons:
- Tests can be flaky
- Takes longer to run
- Can't test specific scenarios

## Strategy 5: Hybrid Approach (Best Practice)

Combine multiple strategies:

```typescript
describe('Battle System', () => {
  // Use mocking for specific scenarios
  test('player loses with low rolls', async () => {
    await startGame({ fixedDiceRolls: [1, 1] });
    // Test loss scenario
  });
  
  // Use probability for balance
  test('battles are balanced', async () => {
    const results = await runManyBattles(100);
    expect(results.winRate).toBeBetween(0.4, 0.6);
  });
  
  // Use seed for complex scenarios
  test('full game playthrough', async () => {
    await startGame({ seed: 'test-seed-1' });
    // Test complete game
  });
});
```

## Implementation Example

Here's how we implemented it for monadungeon:

```typescript
export class GameApiMock {
  async setup() {
    // Control battle dice
    await this.page.route('**/game/start-battle', (route) => {
      // Modify response with fixed values
    });
    
    // Control tile draws
    await this.page.route('**/game/pick-tile', (route) => {
      // Return predetermined tile
    });
    
    // Control monster encounters
    await this.page.route('**/game/move-player', (route) => {
      // Force or prevent encounters
    });
  }
}
```

## Best Practices

1. **Document Random Elements**: List all sources of randomness
2. **Use Fixtures**: Create reusable test setups
3. **Test Both Ways**: Include tests with and without mocking
4. **Version Control**: Track API changes that affect mocks
5. **Performance**: Mock only what's necessary

## Common Pitfalls

1. **Over-Mocking**: Don't mock everything, just randomness
2. **Brittle Tests**: Update mocks when API changes
3. **Missing Edge Cases**: Test both lucky and unlucky scenarios
4. **Race Conditions**: Ensure mocks are set up before requests

## Testing Checklist

- [ ] Identify all random elements
- [ ] Choose appropriate strategy for each
- [ ] Create test fixtures
- [ ] Write tests for edge cases
- [ ] Add probability tests for balance
- [ ] Document mocking approach
- [ ] Set up CI/CD integration