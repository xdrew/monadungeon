# Scenario 04: Player 1 Turn 3 - Victory Against Giant Rat

## Overview
Player 1 places a two-sided straight room tile and defeats a Giant Rat, obtaining a dagger weapon.

## Turn Details
- **Turn Number:** 3
- **Player:** Player 1 (9d27e23c-43c3-4c2e-8595-4c233239cd61)
- **Turn ID:** 0198be90-6a44-73ed-9c51-ad467e358c3d
- **Action:** Place tile at (0,-2), win battle, collect dagger

## API Call Sequence

### 1. Pick Tile
**Request:** `POST /api/game/pick-tile`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "tileId": "1f10fd27-a0c8-413d-8cda-f65210500162",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-6a44-73ed-9c51-ad467e358c3d",
  "requiredOpenSide": 2
}
```
**Response:** Status 201
```json
{
  "tile": {
    "orientation": "true,false,true,false",
    "features": [],
    "tileId": "1f10fd27-a0c8-413d-8cda-f65210500162",
    "room": true
  }
}
```

### 2. Place Tile
**Request:** `POST /api/game/place-tile`
```json
{
  "tileId": "1f10fd27-a0c8-413d-8cda-f65210500162",
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "fieldPlace": "0,-2",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-6a44-73ed-9c51-ad467e358c3d"
}
```

### 3. Move and Battle
**Request:** `POST /api/game/move-player`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-6a44-73ed-9c51-ad467e358c3d",
  "fromPosition": "0,-1",
  "toPosition": "0,-2",
  "ignoreMonster": false,
  "isTilePlacementMove": true
}
```
**Response:** Status 200
```json
{
  "battleInfo": {
    "battleId": "0198be90-7477-70cf-8ab0-d24c37dfa0e7",
    "player": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
    "position": "0,-2",
    "monster": 5,
    "diceResults": [4, 3],
    "diceRollDamage": 7,
    "itemDamage": 0,
    "totalDamage": 7,
    "usedItems": [],
    "result": "win",
    "monsterType": "giant_rat",
    "reward": {
      "name": "dagger",
      "type": "dagger",
      "treasureValue": 0,
      "itemId": "0198be90-3065-73df-a583-48885242413d"
    }
  }
}
```

### 4. Pick Up Item
**Request:** `POST /api/game/pick-item`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "turnId": "0198be90-6a44-73ed-9c51-ad467e358c3d",
  "position": "0,-2"
}
```

### 5. End Turn
**Request:** `POST /api/game/end-turn`

## Battle Mechanics

### Battle Details
- **Monster:** Giant Rat
- **Monster HP:** 5
- **Dice Roll:** [4, 3] = 7 damage
- **Item Damage:** 0
- **Total Damage:** 7
- **Result:** WIN (7 > 5)

### Reward
- **Item Type:** Dagger (weapon)
- **Damage Bonus:** +1
- **Treasure Value:** 0

## Game State Changes

### Player Inventory
- Added first weapon: Dagger
- Current inventory:
  - 1 key (skeleton_turnkey)
  - 1 dagger (giant_rat)

### Board State
- New tile at (0,-2)
- Tile orientation: Vertical corridor (║)
- Player 1 position: (0,-2)

## Important Notes
1. Two-sided room tiles create corridors
2. Daggers provide +1 damage in future battles
3. Weapons are automatically used in battles
4. Player 1 now has both a key and a weapon