# Scenario 05: Player 2 Turn 4 - Movement and Victory Against Mummy

## Overview
Player 2 first moves to the healing fountain, then places a four-sided room tile and defeats a Mummy to obtain a fireball spell.

## Turn Details
- **Turn Number:** 4 (displayed as Turn 5 in UI)
- **Player:** Player 2 (0bafff62-8545-4e69-813d-145fda9535c0)
- **Turn ID:** 0198be90-892e-7242-b3ce-e26f859faf77
- **Action:** Move to (0,0), place tile at (0,1), defeat Mummy

## API Call Sequence

### 1. Move to Healing Fountain
**Request:** `POST /api/game/move-player`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-892e-7242-b3ce-e26f859faf77",
  "fromPosition": "2,0",
  "toPosition": "0,0",
  "ignoreMonster": false,
  "isTilePlacementMove": false
}
```
**Note:** No battle triggered (healing fountain tile)

### 2. Pick and Place Tile
**Request:** `POST /api/game/place-tile`
```json
{
  "tileId": "234d2cb9-9101-4c01-ba1a-20c12b24eee3",
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "fieldPlace": "0,1",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-892e-7242-b3ce-e26f859faf77"
}
```

### 3. Move and Battle
**Request:** `POST /api/game/move-player`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-892e-7242-b3ce-e26f859faf77",
  "fromPosition": "0,0",
  "toPosition": "0,1",
  "ignoreMonster": false,
  "isTilePlacementMove": true
}
```
**Response:**
```json
{
  "battleInfo": {
    "battleId": "0198be90-91f2-7323-acb8-3f866f2e9a0f",
    "player": "0bafff62-8545-4e69-813d-145fda9535c0",
    "position": "0,1",
    "monster": 7,
    "diceResults": [5, 5],
    "diceRollDamage": 10,
    "itemDamage": 0,
    "totalDamage": 10,
    "result": "win",
    "monsterType": "mummy",
    "reward": {
      "name": "fireball",
      "type": "fireball",
      "treasureValue": 0,
      "itemId": "0198be90-3065-73df-a583-4888533f581c"
    }
  }
}
```

### 4. Pick Up Fireball
**Request:** `POST /api/game/pick-item`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-892e-7242-b3ce-e26f859faf77",
  "position": "0,1"
}
```

## Battle Mechanics

### Battle Details
- **Monster:** Mummy
- **Monster HP:** 7
- **Dice Roll:** [5, 5] = 10 damage
- **Item Damage:** 0 (no weapons)
- **Total Damage:** 10
- **Result:** WIN (10 > 7)

### Reward
- **Item Type:** Fireball (spell/consumable)
- **Effect:** One-time use, adds 9 damage
- **Treasure Value:** 0

## Game State Changes

### Player 2 Status
- HP remains at 1 (no healing yet)
- Position: (0,1)
- Inventory: Added fireball spell

### Movement Pattern
- Player 2 moved from (2,0) → (0,0) → (0,1)
- Strategic movement through healing fountain

## Important Notes
1. Player can move before placing a tile
2. Healing fountain doesn't heal immediately on entry
3. Fireball is a consumable that can be used in future battles
4. Multiple moves allowed in a single turn