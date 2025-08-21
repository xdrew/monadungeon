# Monadungeon Game Rules

This document outlines all the rules of the Monadungeon

## Game Overview
Monadungeon is a dungeon-crawling adventure game where players explore a labyrinth, fight monsters, collect treasures, and race to defeat the final boss to claim the most victory points.

## Game Setup ✅
- **Players**: 1-4 players maximum ✅
- **Starting Position**: All players start at position (0,0) on the central starting tile ✅
- **Initial HP**: Each player starts with 5 HP tokens ✅
- **Starting Tile**: A pre-placed starting tile is set at position (0,0) ✅
- **Turn Order**: Players take turns clockwise starting with player 1 ✅

## Turn Structure ✅
Each player has **4 movement actions** per turn. During their turn, players can:
- Move through discovered tiles (costs 1 movement) ✅
- Discover new tiles by moving into unexplored areas ✅
- Use teleportation gates (costs 1 movement) ✅
- Combat with monsters automatically occurs when entering a room with a monster ✅
- Use items or spells ✅
- End turn early ✅

## Tile System

### Tile Placement Rules ✅
When entering an undiscovered zone:
1. Draw a random tile from the deck ✅
2. Place it connecting to the tile you're leaving from ✅
3. The tile must have at least one matching open side ✅
4. Other sides can be dead ends (walls) ✅

### Tile Types
- **Corridors/Tunnels**: Empty passages with 2, 3, or 4 connections ✅
- **Rooms**: Can contain monsters or treasures ✅
- **Starting Tile**: The central tile where players begin ✅
- **Teleportation Gates**: Allow instant travel between discovered gates ✅
- **Healing Fountains**: Restore all HP and remove curses ✅

## Movement Mechanics ✅
- Players can move up to 4 tiles per turn ✅
- Movement must follow connected passages (cannot pass through walls) ✅
- Cannot pass through monsters - must fight them ✅
- Movement ends if:
  - All 4 moves are used ✅
  - A battle occurs ✅
  - Player unlocks a chest ✅
  - Player picks up equipment ✅
  - Player heals at a fountain ✅
  - Player uses certain actions that end the turn ✅

## Battle System ✅

### Combat Mechanics
1. **Automatic Combat**: Battles occur automatically when entering a room with a monster ✅
2. **Dice Roll**: Roll 2 six-sided dice ✅
3. **Damage Calculation**: ✅
   - Base damage = sum of dice
   - Add weapon damage bonus
   - Can use consumable spell bonuses
4. **Battle Outcomes**: ✅
   - **WIN** (damage > monster HP): Defeat monster, collect loot
   - **DRAW** (damage = monster HP): No damage to either side, monster remains
   - **LOSE** (damage < monster HP): Lose 1 HP, move back to previous tile


## Inventory System ✅

### Inventory Limits ✅
- **Keys**: Maximum 1
- **Weapons**: Maximum 2
- **Spells**: Maximum 3
- **Treasures**: Unlimited

### Item Types ✅
1. **Weapons** (Permanent damage bonus):
   - Dagger: +1 damage ✅
   - Sword: +2 damage ✅
   - Axe: +3 damage ✅

2. **Spells** (Consumable):
   - Fireball: +1 damage to next attack ✅
   - Portal of Healing: Restore all HP ✅
   - Teleport: Move to any healing fountain tile and end turn ✅

3. **Keys**: Required to unlock treasure chests ✅

4. **Treasures**:
   - Regular Chest: 2 Victory Points ✅
   - Ruby Chest: 3 Victory Points ✅

### Item Management ✅
- If inventory is full when gaining an item, must drop an existing item ✅
- Dropped items remain on the current tile ✅
- Other players can pick up dropped items ✅

## Special Mechanics

### Stunning/Defeat ✅
When a player's HP reaches 0:
1. Player is stunned/defeated ✅
2. Player remains on their current tile ✅
3. Skip their next turn ✅
4. Regenerate to 1 HP at the start of the skipped turn ✅


### Healing Fountains ✅
- Special tiles that restore all HP ✅
- End the player's turn immediately ✅


## Victory Conditions ✅
1. **Game End**: When any player defeats the final boss ✅
2. **Winner**: Player with the most Victory Points ✅
3. **Victory Points Sources**:
   - Regular treasure chests: 2 VP each ✅
   - Final bosses ruby chest: 3 VP ✅
   - Fallen's treasure: 2 VP ✅

## Turn Action Flow ✅
1. Start turn with 4 movement points ✅
2. Choose action:
   - Move to adjacent discovered tile ✅
   - Discover new tile ✅
   - Pick up item from current tile ✅
   - Use consumable spell ✅
   - Skip remaining actions ✅
3. Automatic events:
   - Combat when entering room with monster ✅
   - Pick up or leave loot after winning battle ✅
4. Turn ends when:
   - All 4 actions used ✅
   - Combat occurs ✅
   - Certain actions are taken (unlock chest, heal, etc.) ✅

## Game Constants ✅
- `MAX_PLAYERS_COUNT` = 4
- `MAX_HP` = 5
- `MAX_ACTIONS_PER_TURN` = 4
- Inventory limits: 1 key, 2 weapons, 3 spells
- Starting position: (0, 0)

## Implementation Status Summary

### Fully Implemented ✅
- Core game setup and turn structure
- Tile placement and exploration
- Basic movement mechanics
- Complete battle system
- Inventory management
- Victory conditions
- Player stunning/defeat mechanics
