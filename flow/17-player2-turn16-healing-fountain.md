# Scenario 17: Player 2 Turn 16 - Healing Fountain Recovery

## Overview
Player 2 places a healing fountain tile, then a room. After losing the battle, they step back to the healing fountain and recover HP.

## Turn Details
- **Turn Number:** 16
- **Player:** Player 2 (0bafff62-8545-4e69-813d-145fda9535c0)
- **Action:** Place healing fountain tile, place room, lose battle, step back and heal

## API Sequence

### 1. Place Healing Fountain Tile
**Request:** `POST /api/game/place-tile`
```json
{
  "tileId": "healing-fountain-tile-id",
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "fieldPlace": "4,0",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "turn-id"
}
```

### 2. Place Room Tile
**Request:** `POST /api/game/place-tile`
```json
{
  "fieldPlace": "5,0"
}
```

### 3. Move and Battle (LOSE)
**Response:**
```json
{
  "battleInfo": {
    "result": "loose",
    "monster": 15,
    "totalDamage": 9,
    "monsterType": "dragon"
  }
}
```

## Healing Fountain Mechanics

### Step Back Effect
- **Trigger:** Losing battle in adjacent room
- **Movement:** Automatic step back to healing fountain
- **Healing:** HP restored from 0 to 1
- **Position:** Player moves from (5,0) back to (4,0)

### Healing Rules
1. **Auto-heal:** Triggered when stepping on healing fountain
2. **Step-back:** Occurs after battle loss
3. **HP Recovery:** Restores health based on fountain power
4. **Safe Position:** Healing fountain provides refuge

## Game State Changes
- Player 2 HP: 0 → 1 (healed)
- Position: (5,0) → (4,0) (stepped back)
- Dragon remains undefeated at (5,0)
- Healing fountain available for future use

## Strategic Value
1. **Recovery Point:** Safe healing location
2. **Retry Opportunity:** Can attempt battle again
3. **HP Management:** Critical for low-HP players
4. **Positioning:** Strategic tile placement

## Important Notes
1. Healing fountains auto-heal on entry
2. Step-back is automatic after battle loss
3. Healing amount varies by fountain type
4. Multiple fountains can exist on board