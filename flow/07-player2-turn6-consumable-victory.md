# Scenario 07: Player 2 Turn 6 - Using Fireball Consumable to Win

## Overview
Player 2 places a four-sided room and initially draws against a Skeleton Turnkey. Uses the fireball consumable to win the battle and obtain a key.

## Turn Details
- **Turn Number:** 6
- **Player:** Player 2 (0bafff62-8545-4e69-813d-145fda9535c0)
- **Action:** Place tile at (1,1), use fireball to defeat Skeleton Turnkey

## Battle Sequence

### Initial Battle Result
**Request:** `POST /api/game/move-player`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-b1da-736e-98f3-c3a4028155a8",
  "fromPosition": "0,1",
  "toPosition": "1,1",
  "ignoreMonster": false,
  "isTilePlacementMove": true
}
```

**Response:**
```json
{
  "battleInfo": {
    "battleId": "0198be90-c22f-72ed-a976-bcd42c2fd4ac",
    "player": "0bafff62-8545-4e69-813d-145fda9535c0",
    "position": "1,1",
    "monster": 8,
    "diceResults": [4, 4],
    "diceRollDamage": 8,
    "itemDamage": 0,
    "totalDamage": 8,
    "result": "draw",
    "availableConsumables": [
      {
        "itemId": "0198be90-3065-73df-a583-4888533f581c",
        "name": "mummy",
        "type": "fireball",
        "guardHP": 7
      }
    ],
    "needsConsumableConfirmation": true,
    "monsterType": "skeleton_turnkey",
    "reward": {
      "name": "key",
      "type": "key",
      "isPotentialReward": true
    }
  }
}
```

### Using Consumable
**Request:** `POST /api/game/finalize-battle`
```json
{
  "battleId": "0198be90-c22f-72ed-a976-bcd42c2fd4ac",
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-b1da-736e-98f3-c3a4028155a8",
  "selectedConsumableIds": ["0198be90-3065-73df-a583-4888533f581c"],
  "pickupItem": true
}
```

## Battle Mechanics
- **Initial Roll:** [4, 4] = 8 damage (DRAW)
- **Fireball Bonus:** +9 damage
- **Final Damage:** 8 (roll) + 9 (fireball) = 17
- **Result:** WIN (17 > 8 HP)

## Key Mechanics
1. **Draw Condition:** When damage equals monster HP
2. **Consumable Usage:** Player can choose to use consumables on draw
3. **Fireball:** One-time use, adds flat 9 damage
4. **Item Pickup:** Specified in finalize-battle call

## Game State Changes
- Player 2 gains a key
- Fireball consumable consumed (removed from inventory)
- Player 2 position: (1,1)

## Important Notes
1. Consumables only offered when result is draw or loss
2. Fireball name shown as "mummy" (from source monster)
3. isPotentialReward indicates item available if battle won