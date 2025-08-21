# Scenario 08: Player 1 Turn 7 - Inventory Full and Item Replacement

## Overview
Player 1 defeats a Skeleton Warrior but inventory is full. Must choose which weapon to replace.

## Turn Details
- **Turn Number:** 7
- **Player:** Player 1 (9d27e23c-43c3-4c2e-8595-4c233239cd61)
- **Action:** Place tile, defeat Skeleton Warrior, replace dagger with sword

## Battle Mechanics
- **Monster:** Skeleton Warrior
- **Monster HP:** 9
- **Dice Roll:** [5, 5] = 10 damage
- **Item Damage:** 2 (both daggers)
- **Total Damage:** 12
- **Result:** WIN (12 > 9)

## Inventory Management

### Pick Item Response
**Response:**
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "item": {
    "name": "skeleton_warrior",
    "type": "sword",
    "itemId": "0198be90-3065-73df-a583-488854eb8855"
  },
  "inventoryFull": true,
  "itemCategory": "weapon",
  "maxItemsInCategory": 2,
  "currentInventory": [
    {
      "itemId": "0198be90-3065-73df-a583-48885242413d",
      "name": "giant_rat",
      "type": "dagger"
    },
    {
      "itemId": "0198be90-3065-73df-a583-488853da054c",
      "name": "giant_rat",
      "type": "dagger"
    }
  ],
  "missingKey": false,
  "chestType": null,
  "itemReplaced": false
}
```

### Item Replacement
**Request:** `POST /api/game/inventory-action`
```json
{
  "gameId": "876b520e-761f-4bbd-9eaa-cec05fb77800",
  "playerId": "9d27e23c-43c3-4c2e-8595-4c233239cd61",
  "action": "replace",
  "item": {
    "name": "skeleton_warrior",
    "type": "sword",
    "itemId": "0198be90-3065-73df-a583-488854eb8855"
  },
  "itemIdToReplace": "0198be90-3065-73df-a583-48885242413d"
}
```

## Key Mechanics
1. **Inventory Limits:** 2 weapons maximum
2. **Replacement Required:** When inventory full
3. **Item Selection:** Player chooses which item to replace
4. **Weapon Values:** Sword (+2 damage) vs Dagger (+1 damage)

## Final Inventory
- 1 key (skeleton_turnkey)
- 1 dagger (giant_rat) - remaining
- 1 sword (skeleton_warrior) - new
- Total weapon damage: +3

## Important Notes
1. Swords provide more damage than daggers
2. Player strategically replaced one dagger
3. Inventory management is mandatory when full
4. API shows current inventory for decision making