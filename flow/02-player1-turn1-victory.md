# Scenario 02: Player 1 Turn 1 - Victory Against Skeleton Turnkey

## Overview
Player 1's first turn involves placing a four-sided room tile, moving to it, and winning a battle against a Skeleton Turnkey to obtain a key.

## Turn Details
- **Turn Number:** 1
- **Player:** Player 1 (9d27e23c-43c3-4c2e-8595-4c233239cd61)
- **Turn ID:** 0198be90-307f-7049-ab38-0fd9e2cb3a88
- **Action:** Place tile at (0,-1), move and battle

## API Call Sequence

### 1. Pick Tile
**Request:** `POST /api/game/pick-tile`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "tileId": "ce95b389-841a-4474-a8f0-550182e85aac",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-307f-7049-ab38-0fd9e2cb3a88",
  "requiredOpenSide": 2
}
```
**Response:** Status 201
```json
{
  "tile": {
    "orientation": "true,true,true,true",
    "features": [],
    "tileId": "ce95b389-841a-4474-a8f0-550182e85aac",
    "room": true
  }
}
```

### 2. Place Tile
**Request:** `POST /api/game/place-tile`
```json
{
  "tileId": "ce95b389-841a-4474-a8f0-550182e85aac",
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "fieldPlace": "0,-1",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-307f-7049-ab38-0fd9e2cb3a88"
}
```
**Response:** Status 200
```json
{
  "tile": {
    "orientation": "true,true,true,true",
    "features": [],
    "tileId": "ce95b389-841a-4474-a8f0-550182e85aac",
    "room": true
  }
}
```

### 3. Move Player and Trigger Battle
**Request:** `POST /api/game/move-player`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-307f-7049-ab38-0fd9e2cb3a88",
  "fromPosition": "0,0",
  "toPosition": "0,-1",
  "ignoreMonster": false,
  "isTilePlacementMove": true
}
```
**Response:** Status 200
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "battleInfo": {
    "battleId": "0198be90-4527-736d-9999-7707b3be0191",
    "player": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
    "position": "0,-1",
    "monster": 8,
    "diceResults": [6, 6],
    "diceRollDamage": 12,
    "itemDamage": 0,
    "totalDamage": 12,
    "usedItems": [],
    "result": "win",
    "availableConsumables": [],
    "needsConsumableConfirmation": false,
    "monsterType": "skeleton_turnkey",
    "reward": {
      "name": "key",
      "type": "key",
      "treasureValue": 0,
      "itemId": "0198be90-3064-7327-b136-2f10fe6b4c5b",
      "autoCollected": false
    }
  },
  "itemInfo": null
}
```

### 4. Pick Up Item
**Request:** `POST /api/game/pick-item`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-307f-7049-ab38-0fd9e2cb3a88",
  "position": "0,-1"
}
```
**Response:** Status 200
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "item": {
    "locked": false,
    "guardDefeated": true,
    "treasureValue": 0,
    "guardHP": 8,
    "itemId": "0198be90-3064-7327-b136-2f10fe6b4c5b",
    "endsGame": false,
    "name": "skeleton_turnkey",
    "type": "key"
  },
  "inventoryFull": false,
  "itemCategory": null,
  "maxItemsInCategory": null,
  "currentInventory": null,
  "missingKey": false,
  "chestType": null,
  "itemReplaced": false
}
```

### 5. End Turn
**Request:** `POST /api/game/end-turn`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-307f-7049-ab38-0fd9e2cb3a88"
}
```
**Response:** Status 200
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-307f-7049-ab38-0fd9e2cb3a88",
  "success": true,
  "message": "Turn ended successfully"
}
```

## Battle Mechanics

### Battle Details
- **Monster:** Skeleton Turnkey
- **Monster HP:** 8
- **Dice Roll:** [6, 6] = 12 damage
- **Item Damage:** 0 (no weapons)
- **Total Damage:** 12
- **Result:** WIN (12 damage > 8 HP)

### Reward
- **Item Type:** Key
- **Item Name:** skeleton_turnkey
- **Treasure Value:** 0
- **Auto-collected:** No (requires manual pickup)

## Game State Changes

### Board Changes
- New tile placed at position (0,-1)
- Tile is a four-sided room (all sides open)
- New available places: (0,-2), (1,-1), (-1,-1) plus existing ones

### Player State Changes
- Player 1 moved from (0,0) to (0,-1)
- Player 1 inventory: Added 1 key
- HP remains at 5 (no damage taken on victory)

### Next Turn
- Turn advances to 2
- Control passes to Player 2 (0bafff62-8545-4e69-813d-145fda9535c0)

## Important Notes
1. Battle is triggered automatically when moving to a room tile
2. Victory condition: Total damage > Monster HP
3. Keys are not auto-collected and require explicit pickup
4. The `isTilePlacementMove: true` flag indicates this is the mandatory move after placing a tile
5. Dice rolls were predetermined in test setup ([6,6] for this battle)