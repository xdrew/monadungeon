# Scenario 18: Player 2 Turn 18 - Teleportation Between Gates

## Overview
Player 2 demonstrates teleportation mechanics by moving between the two teleportation gates on the board.

## Turn Details
- **Turn Number:** 18
- **Player:** Player 2 (0bafff62-8545-4e69-813d-145fda9535c0)
- **Action:** Move from healing fountain to gate, teleport to other gate

## Teleportation Sequence

### 1. Move from Healing Fountain
**Start Position:** (4,0) - healing fountain
**Action:** Move to adjacent position

### 2. Move to First Gate
**Request:** `POST /api/game/move-player`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "fromPosition": "current-position",
  "toPosition": "3,1",
  "ignoreMonster": false,
  "isTilePlacementMove": false
}
```

### 3. Teleport to Second Gate
**Request:** `POST /api/game/move-player`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "0bafff62-8545-4e69-813d-145fda9535c0",
  "fromPosition": "3,1",
  "toPosition": "1,0",
  "ignoreMonster": false,
  "isTilePlacementMove": false
}
```

## Teleportation Mechanics

### Gate Positions
- **Gate 1:** Position (1,0) - first teleportation gate
- **Gate 2:** Position (3,1) - second teleportation gate

### Movement Rules
1. **Gate Entry:** Player moves to teleportation gate tile
2. **Teleport Option:** Game offers movement to linked gate
3. **Instant Travel:** No movement cost between gates
4. **Bidirectional:** Can teleport in either direction

### Movement Markers
API shows available teleportation destinations:
```json
{
  "availablePlaces": {
    "moveTo": ["1,0", "other-positions"]
  }
}
```

## Strategic Advantages
1. **Rapid Movement:** Cross large distances instantly
2. **Tactical Positioning:** Access to multiple board areas
3. **Escape Route:** Quick exit from dangerous positions
4. **Board Control:** Enhanced mobility options

## End Turn
**Request:** `POST /api/game/end-turn`
- Player successfully teleported
- Final position: (1,0) - first teleportation gate
- Turn completed normally

## Important Notes
1. Teleportation gates must be paired
2. No limit on teleportation usage
3. Teleportation doesn't trigger battles
4. Multiple moves allowed in single turn
5. Gates remain active throughout game