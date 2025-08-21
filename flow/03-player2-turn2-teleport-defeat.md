# Scenario 03: Player 2 Turn 2 - Teleportation Gate and Defeat by Skeleton King

## Overview
Player 2 places two tiles in sequence: first a teleportation gate (non-room corridor), then a three-sided room. When entering the room, Player 2 loses to a Skeleton King and takes damage.

## Turn Details
- **Turn Number:** 2
- **Player:** Player 2 (0bafff62-8545-4e69-813d-145fda9535c0)
- **Turn ID:** 0198be90-4ba2-7243-818a-fbd1f2e7615c
- **Action:** Place teleportation gate, then room tile, lose battle

## API Call Sequence

### 1. Pick First Tile (Teleportation Gate)
**Request:** `POST /api/game/pick-tile`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "tileId": "5b2a81dc-0678-414b-ab97-cd11ebacc79e",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-4ba2-7243-818a-fbd1f2e7615c",
  "requiredOpenSide": 3
}
```
**Response:** Status 201
```json
{
  "tile": {
    "orientation": "false,true,false,true",
    "features": ["teleportation_gate"],
    "tileId": "5b2a81dc-0678-414b-ab97-cd11ebacc79e",
    "room": false
  }
}
```

### 2. Place Teleportation Gate Tile
**Request:** `POST /api/game/place-tile`
```json
{
  "tileId": "5b2a81dc-0678-414b-ab97-cd11ebacc79e",
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "fieldPlace": "1,0",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-4ba2-7243-818a-fbd1f2e7615c"
}
```
**Response:** Status 200

### 3. Move to Teleportation Gate (No Battle)
**Request:** `POST /api/game/move-player`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-4ba2-7243-818a-fbd1f2e7615c",
  "fromPosition": "0,0",
  "toPosition": "1,0",
  "ignoreMonster": false,
  "isTilePlacementMove": true
}
```
**Response:** Status 200
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "battleInfo": null,
  "itemInfo": null
}
```

### 4. Pick Second Tile (Room)
**Request:** `POST /api/game/pick-tile`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "tileId": "6bc7b942-1fd5-4207-a07a-b1783a57187b",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-4ba2-7243-818a-fbd1f2e7615c",
  "requiredOpenSide": 3
}
```
**Response:** Status 201
```json
{
  "tile": {
    "orientation": "false,true,true,true",
    "features": [],
    "tileId": "6bc7b942-1fd5-4207-a07a-b1783a57187b",
    "room": true
  }
}
```

### 5. Place Room Tile
**Request:** `POST /api/game/place-tile`
```json
{
  "tileId": "6bc7b942-1fd5-4207-a07a-b1783a57187b",
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "fieldPlace": "2,0",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-4ba2-7243-818a-fbd1f2e7615c"
}
```
**Response:** Status 200

### 6. Move to Room and Trigger Battle (LOSE)
**Request:** `POST /api/game/move-player`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-4ba2-7243-818a-fbd1f2e7615c",
  "fromPosition": "1,0",
  "toPosition": "2,0",
  "ignoreMonster": false,
  "isTilePlacementMove": true
}
```
**Response:** Status 200
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "battleInfo": {
    "battleId": "0198be90-652e-73f2-ae55-c041961e0e88",
    "player": "0bafff62-8545-4e69-813d-145fda9535c0",
    "position": "2,0",
    "monster": 10,
    "diceResults": [1, 1],
    "diceRollDamage": 2,
    "itemDamage": 0,
    "totalDamage": 2,
    "usedItems": [],
    "result": "loose",
    "availableConsumables": [],
    "needsConsumableConfirmation": true,
    "monsterType": "skeleton_king"
  },
  "itemInfo": null
}
```

### 7. Finalize Battle (Confirm Loss)
**Request:** `POST /api/game/finalize-battle`
```json
{
  "battleId": "0198be90-652e-73f2-ae55-c041961e0e88",
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-4ba2-7243-818a-fbd1f2e7615c",
  "selectedConsumableIds": [],
  "pickupItem": false
}
```
**Response:** Status 200
```json
{
  "battleId": "0198be90-652e-73f2-ae55-c041961e0e88",
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "finalTotalDamage": 0,
  "success": true,
  "itemPickedUp": false
}
```

### 8. End Turn
**Request:** `POST /api/game/end-turn`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "turnId": "0198be90-4ba2-7243-818a-fbd1f2e7615c"
}
```
**Response:** Status 200

## Battle Mechanics

### Battle Details
- **Monster:** Skeleton King
- **Monster HP:** 10
- **Dice Roll:** [1, 1] = 2 damage
- **Item Damage:** 0 (no weapons)
- **Total Damage:** 2
- **Result:** LOSE (2 damage < 10 HP)

### Consequences of Loss
- Player 2 takes 1 damage (HP: 2 → 1)
- Player stays on the tile (position 2,0)
- Item remains locked (guarded by undefeated monster)
- Monster remains undefeated for future attempts

## Game State Changes

### Board Changes
- Two new tiles placed:
  1. Teleportation gate at (1,0) - corridor tile
  2. Three-sided room at (2,0)
- New available places expanded

### Player State Changes
- Player 2 moved from (0,0) → (1,0) → (2,0)
- Player 2 HP reduced: 2 → 1
- No items gained (battle lost)

### Item State
- Skeleton King's axe remains locked at position (2,0)
- Item details:
  - Type: axe
  - Guard HP: 10
  - Guard defeated: false
  - Treasure value: 0

## Important Notes
1. **Two-tile placement rule:** When placing a non-room tile (corridor/special), player must place another tile
2. **Teleportation gates:** Do not trigger battles when entered
3. **Battle loss consequences:** Player takes 1 damage and stays on tile
4. **needsConsumableConfirmation:** True when battle is lost, allowing player to use consumables
5. **Dice rolls:** Predetermined [1,1] resulting in minimum damage
6. **HP tracking:** Player 2 started with only 2 HP (test configuration) and is now at 1 HP