# Scenario 09: Player 2 Turn 8 - Gets Stunned

## Overview
Player 2 places a room tile and loses to a Skeleton King, taking damage and becoming stunned.

## Turn Details
- **Turn Number:** 8
- **Player:** Player 2 (0bafff62-8545-4e69-813d-145fda9535c0)
- **Action:** Place tile at (1,2), lose to Skeleton King, get stunned

## Battle Mechanics
- **Monster:** Skeleton King
- **Monster HP:** 10
- **Dice Roll:** [1, 1] = 2 damage
- **Item Damage:** 0 (no weapons)
- **Total Damage:** 2
- **Result:** LOSE (2 < 10)

## Status Effects
- **Damage Taken:** 1 HP
- **HP Change:** 1 → 0 (critical condition)
- **Stunned:** Yes (will skip next turn)
- **Position:** Remains at (1,2)

## Game State Changes
- Player 2 HP reaches 0 (critical but not defeated)
- Stunned status applied
- Monster remains undefeated at (1,2)
- Turn advances to next player

## Important Notes
1. HP can reach 0 without player defeat
2. Stunned players skip their next turn
3. Critical HP triggers stun effect
4. Monster guards item until defeated