# Monadungeon Game Rules

This document outlines all the rules of the Monadungeon

## Game Overview
Monadungeon is a dungeon-crawling adventure game where players explore a labyrinth, fight monsters, collect treasures, and race to defeat the final boss to claim the most victory points.

## Game Setup ✅
- **Players**: 1-5 players maximum (five hero stands) ✅
- **Starting Position**: All players start at position (0,0) on the central starting tile ✅
- **Initial HP**: Each player starts with 5 HP tokens ✅
- **Starting Tile**: A pre-placed starting tile is set at position (0,0) ✅
- **Turn Order**: every player rolls 2d6, the highest roll starts (a tie goes to the first of the tied players in seating order), then play goes clockwise; in test mode player 1 starts and no dice are spent ✅

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
   - Portal of Healing (teleport scroll): carry yourself or any other player to a revealed healing fountain, heal them fully and lift their curse; costs no move and does not end the turn ✅
   - Frost Fist (Karak: Regent): +2 in a fight, chosen after the roll, several per fight, combinable; the Wizard keeps it ✅
   - Dark Thorn (Karak: Regent): on your turn another hero loses 1 HP; costs no move, several per turn ✅

3. **Keys**: Required to unlock treasure chests ✅

4. **Treasures**:
   - Regular Chest: 2 Victory Points ✅
   - Ruby Chest: 3 Victory Points ✅

### Item Management ✅
- If inventory is full when gaining an item, must drop an existing item ✅
- Dropped items remain on the current tile ✅
- Other players can pick up dropped items ✅

## Special Mechanics

### The Curse of Karak ✅
- Whoever defeats a mummy chooses another player to curse; a virtual player curses the first human ✅
- There is a single curse token: cursing a new player lifts it from the previous holder ✅
- When the token is already in play the slayer may leave it where it is ✅
- A cursed hero has no skills: the Warrior cannot reroll or reincarnate, the Thief loses ties and cannot sneak, the Swordsman keeps his 1s and his turn ends after any fight, the Warlock cannot sacrifice HP or swap places, the Wizard's fireballs are consumed and he cannot walk through walls, the Oracle gets no +1 and draws a single token ✅
- The curse is lifted by ending the turn on a healing fountain (together with the healing) or by a Portal of Healing ✅
- The choice is made in the battle report before picking up the reward; a choice left unmade is settled when the turn ends ✅
- Single-player games have no curse ✅

### Karak: Regent (expansion) ✅
- **Switch in the lobby** ("Karak: Regent expansion"): the deck gets 18 more tiles (6 Battle Arenas, 4 Cursed Rooms, 8 rooms) and the bag 6 Giant Bats and 2 Skeleton Mages. The Regent heroes and the Lord Karak variant come later ✅
- **Battle Arena** (a corridor) ✅
  - The first hero to enter it on their own turn chooses another hero; that hero's figure is moved onto the arena and they fight a duel
  - The challenger rolls and decides first (spells, Sacrifice, Double Attack); then the summoned hero rolls; the strength to beat is the other hero's total
  - All combat skills apply, a cursed hero has none; the Oracle gets +1 when the arena is her first step or when she is summoned
  - Higher total wins (a hero who wins ties wins an equal total; both or neither → tie). The winner takes one item they have room for; the loser loses 1 HP; a tie changes nothing
  - Both figures stay on the arena; the challenger's turn goes on unless knocked out
  - A virtual challenger picks the rival with the most treasures; a human summoned during a virtual player's turn is rolled automatically (spells only when they turn the result)
  - A choice left unmade when the turn ends means no duel; an unfinished duel is settled at the turn end (no consumables, best loot automatically)
  - The arena activates once; later entries are a normal corridor
- **Cursed Room** (a corridor) ✅
  - Every hero entering it rolls a die: 1–3 → Curse of Karak, 4–6 → safe; an already cursed hero does not roll; being pushed back onto it after a lost fight rolls again
  - Several heroes may be cursed at once; the mummy token is still single. A healing fountain or the Portal of Healing lifts every curse of that hero
- **Monsters**: Giant Bat (6 HP) drops Dark Thorn; Skeleton Mage (11 HP) drops Frost Fist ✅
- **Spells**: a Warrior losing his last HP to a Dark Thorn or in a duel reincarnates at the nearest discovered fountain automatically ✅

### Stunning/Defeat ✅
When a player's HP reaches 0:
1. Player is stunned/defeated ✅
2. Player remains on their current tile ✅
3. Skip their next turn ✅
4. Regenerate to 1 HP at the start of the skipped turn ✅
5. Exception: a hero with Reincarnation (see Heroes) is not stunned ✅

### Heroes ✅
All hero skills below are switched off while the hero carries the Curse of Karak (see above).
Each player may pick a hero before the game starts (lobby); a player without a hero has no special skills. AI players have no hero. ✅

#### Horan, the Warrior ✅
- **Double Attack** ✅
  - In any combat, after the first roll (dice + weapons) the Warrior may reroll both dice once ✅
  - The first roll is discarded and costs no HP, even if it was a loss ✅
  - The second roll must be accepted; spells can still be used on top of it ✅
  - Rerolling is optional; a winning first roll is final ✅
- **Reincarnation** ✅
  - When the Warrior loses his last HP he does not fall unconscious ✅
  - Instead he chooses any discovered healing fountain tile (the starting tile always has one) and teleports there ✅
  - All HP are replenished ✅
  - The turn ends anyway ✅

#### Aderyn, the Thief ✅
- **Backstab** ✅
  - Any combat that would end in a tie is a victory for the Thief (also when the tie is reached with spells) ✅
- **Stealth** ✅
  - When entering a tile with a monster (by moving or by placing a new room) the Thief chooses: fight it, or sneak past ✅
  - Sneaking costs the move like any other move, the monster stays on the tile, and she may keep moving ✅

#### Victorius, the Swordsman ✅
- **Combat Training** ✅
  - Any die showing 1 is rerolled, as many times as necessary, until it shows 2–6 (automatic, in every combat) ✅
  - Only the Swordsman rerolls ones; the Warrior's Double Attack is a separate skill ✅
- **Unstoppable** ✅
  - If at least one die shows 6 in a combat, the turn continues afterwards whatever the result ✅
  - After a loss or a tie he still loses the HP / is moved back as usual, but may walk onto the monster tile again and fight once more ✅
  - After a win he may pick up or leave the reward and keep moving ✅
  - A Swordsman who loses his last HP falls unconscious and his turn ends like everyone else's ✅
  - The usual limit of 4 actions per turn applies to the rest of the turn ✅

#### Lord Xanros, the Warlock ✅
- **Sacrifice** ✅
  - After the dice are rolled (when the weapons-only roll is a tie or a loss) the Warlock may pay 1 HP for +1 strength, once per combat ✅
  - The HP is paid before the result is resolved, whatever the outcome; the bonus is shown separately from item bonuses ✅
  - He may pay his last HP: he then falls unconscious and skips his next turn (restoring 1 HP), exactly like after a lost fight ✅
  - A fight already won with weapons only is final, no sacrifice is offered ✅
- **Magic Swap** ✅
  - As the first action of his turn the Warlock may trade places with any other hero: he moves to that hero's tile, the other hero to his ✅
  - It costs all 4 moves, so the turn ends right after the swap ✅
  - The ability of the new tile still applies: a reward lying there may be picked up (a chest still needs a key) and a healing fountain heals when the turn ends there ✅
  - Arriving by swap never starts a battle and nothing else about the swapped hero changes ✅

#### Argentus, the Wizard ✅
- **Magical Affinity** ✅
  - Fireballs ("magic bolts") used in a fight stay in the inventory and still give +1 each ✅
  - The teleport scroll ("portal of healing") is consumed as usual ✅
  - A kept fireball does not free a spell slot: with full spell slots a spell reward still has to replace an item ✅
- **Astral Walking** ✅
  - As a normal move (1 of the 4 moves) the Wizard may step onto any orthogonally adjacent discovered tile, even when no door connects them ✅
  - Only discovered tiles: new tiles are still placed only through a door, and nothing changes for diagonal or empty places ✅
  - A monster on the tile behind the wall is fought normally; a reward lying there may be picked up ✅

#### Taia, the Oracle ✅
- **Farseeing** ✅
  - +1 to the roll when the battle starts before any of the turn's 4 moves was spent ✅
  - Placing a tile does not spend a move, so opening a room and fighting its monster gets the +1 ✅
  - Once a move, a teleport or a Magic Swap was spent, later fights in that turn get no bonus ✅
- **Fateweaver** ✅
  - When the Oracle opens a room, two tokens are drawn from the bag instead of one ✅
  - She chooses which token is placed on the room; the other one goes back into the bag at a random position ✅
  - The choice must be made before moving; a choice left unmade when the turn ends keeps the first drawn token ✅
  - With a single token left in the bag it is a normal draw ✅
  - A virtual Oracle chooses automatically: a chest or treasure first, otherwise the weakest monster ✅


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
