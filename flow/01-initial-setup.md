# Scenario 01: Initial Game Setup

## Overview
This scenario covers the initial game setup, including enabling test mode and configuring the game with predetermined dice rolls, tile sequences, and item sequences.

## API Call Sequence

### 1. Enable Test Mode
**Request:** `POST /api/test/toggle-mode`
```json
{
  "enabled": true
}
```
**Response:** Status 200
```json
{
  "enabled": true
}
```

### 2. Setup Game Configuration
**Request:** `POST /api/test/setup-game`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "diceRolls": [6,6,1,1,4,3,5,5,6,6,4,4,5,5,1,1,6,6,4,4,3,3,6,6,3,3,1,1,2,2,6,6],
  "tileSequence": [
    "fourSideRoom",
    {"orientation":"twoSideStraight","room":false,"features":["teleportation_gate"]},
    "threeSideRoom",
    "twoSideStraightRoom",
    "fourSideRoom",
    "threeSideRoom",
    "fourSideRoom",
    "twoSideStraightRoom",
    "threeSideRoom",
    "fourSideRoom",
    "twoSideStraightRoom",
    "threeSideRoom",
    {"orientation":"twoSideStraight","room":false,"features":["teleportation_gate"]},
    "fourSideRoom",
    "fourSideRoom",
    {"orientation":"twoSideCorner","room":false,"features":["healing_fountain"]},
    "threeSideRoom",
    "fourSideRoom"
  ],
  "itemSequence": [
    "skeleton_turnkey",
    "skeleton_king",
    "giant_rat",
    "mummy",
    "giant_rat",
    "skeleton_turnkey",
    "skeleton_warrior",
    "skeleton_king",
    "skeleton_king",
    "skeleton_turnkey",
    "giant_rat",
    "fallen",
    "dragon",
    "skeleton_warrior",
    "skeleton_warrior",
    "dragon"
  ],
  "playerConfigs": {
    "9d27e23c-43c3-4c2e-8595-4c233239cd61": [],
    "0bafff62-8545-4e69-813d-145fda9535c0": {"maxHp": 2}
  }
}
```
**Response:** Status 200
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800"
}
```

### 3. Get Initial Game State
**Request:** `GET /api/game/876b520e-761f-4bbd-9eaa-cec05fb77800`

**Response:** Status 200
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "createdAt": null,
  "state": {
    "status": "started",
    "turn": 1,
    "currentPlayerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
    "currentTurnId": "0198be90-307f-7049-ab38-0fd9e2cb3a88",
    "availablePlaces": {
      "moveTo": ["0,-1","1,0","0,1","-1,0"],
      "placeTile": ["0,-1","1,0","0,1","-1,0"]
    },
    "lastBattleInfo": null,
    "deck": {
      "remainingTiles": 18,
      "isEmpty": false
    }
  },
  "players": [
    {
      "id": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
      "hp": 5,
      "defeated": false,
      "inventory": {
        "keys": [],
        "weapons": [],
        "spells": [],
        "treasures": []
      }
    },
    {
      "id": "0bafff62-8545-4e69-813d-145fda9535c0",
      "hp": 2,
      "defeated": false,
      "inventory": {
        "keys": [],
        "weapons": [],
        "spells": [],
        "treasures": []
      }
    }
  ],
  "settings": [],
  "field": {
    "tiles": [
      {
        "x": 0,
        "y": 0,
        "tileId": "0198be90-307c-734f-9b65-e74d6dcabd19",
        "position": "0,0",
        "features": ["healing_fountain"]
      }
    ],
    "playerPositions": {
      "0bafff62-8545-4e69-813d-145fda9535c0": "0,0",
      "9d27e23c-43c3-4c2e-8595-4c233239cd61": "0,0"
    },
    "availablePlaces": ["0,-1","1,0","0,1","-1,0"],
    "size": {
      "width": 1,
      "height": 1,
      "minX": 0,
      "minY": 0,
      "maxX": 0,
      "maxY": 0
    },
    "tileOrientations": {
      "0,0": "╋"
    },
    "roomFieldPlaces": [],
    "items": [],
    "healingFountainPositions": ["0,0","0,0"]
  }
}
```

## Key Game State Details

### Initial Setup
- **Game ID:** 876b520e-761f-4bbd-9eaa-cec05fb77800
- **Starting Turn:** 1
- **Current Player:** Player 1 (9d27e23c-43c3-4c2e-8595-4c233239cd61)

### Player Configuration
- **Player 1:**
  - ID: 9d27e23c-43c3-4c2e-8595-4c233239cd61
  - HP: 5 (default)
  - Starting position: 0,0
  
- **Player 2:**
  - ID: 0bafff62-8545-4e69-813d-145fda9535c0
  - HP: 2 (custom configuration)
  - Starting position: 0,0

### Starting Board
- Single starting tile at position (0,0) with healing fountain feature
- Available placement positions: (0,-1), (1,0), (0,1), (-1,0)
- Both players start on the healing fountain tile

### Test Configuration
- **Predetermined dice rolls:** 32 dice roll values configured
- **Tile sequence:** 18 tiles including special tiles (teleportation gates, healing fountain)
- **Item sequence:** 16 monsters/items predetermined for battles

## Important Notes
1. Test mode must be enabled before game setup
2. Player 2 has reduced HP (2 instead of 5) for testing purposes
3. The starting tile has a healing fountain feature
4. Turn ID is generated for each turn (UUID format)
5. Both players start at the same position (0,0)