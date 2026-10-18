# Monadungeon Game Rules

This document outlines all the rules of the Monadungeon

## Game Overview
Monadungeon is a dungeon-crawling adventure game where players explore a labyrinth, fight monsters, collect treasures, and race to defeat the final boss to claim the most victory points.

## Game Setup ✅
- **Players**: 1-5 players maximum (five hero stands) ✅
- **Starting Position**: All players start at position (0,0) on the central starting tile ✅
- **Initial HP**: Each player starts with 5 HP tokens ✅
- **Starting Tile**: A pre-placed starting tile is set at position (0,0) ✅
- **Components** (exactly the box, never trimmed): 80 tiles — the start, 53 rooms, 20 corridors, 2 healing fountains, 4 teleportation gates; 53 tokens — 43 monsters with the dragon and 10 chests. Karak: Regent adds 18 tiles and 8 tokens ✅
- **Turn Order**: every player rolls 2d6, the highest roll starts (the players tied for the highest roll again), then play goes clockwise; in test mode player 1 starts and no dice are spent ✅
- **Players at one screen (hot-seat)**: 2–4 seats in one browser, humans and the AI; between two humans' turns the screen is handed over behind a curtain, each human's pack and skills show only after they reveal their turn ✅

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
2. Place it connecting to the tile you're leaving from (it must open toward that tile), then step onto it (only Lady Lorraine may listen instead); the turn cannot end before ✅
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
  - Player unlocks a chest ✅ (on the server: picking up ends the turn, except the reward of an Unstoppable win)
  - Player picks up equipment, only on the tile he stands on ✅
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
   - **DRAW** (damage = monster HP): No damage to either side, monster remains, the hero moves back to the previous tile
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
   - Fireball ("magic bolt"): +1 in the current fight, chosen after the roll, several per fight, then removed from the game ✅
   - Portal of Healing (teleport scroll): carry yourself or any other player to a revealed healing fountain, heal them fully and lift their curse; costs no move and does not end the turn ✅
   - Frost Fist (Karak: Regent): +2 in a fight, chosen after the roll, several per fight, combinable; the Wizard keeps it ✅
   - Dark Thorn (Karak: Regent): on your turn another hero loses 1 HP; costs no move, several per turn ✅

3. **Keys**: Required to unlock a treasure chest that never had a guard; the chest of a defeated guard (the Fallen's) is loot and needs no key ✅

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
- Single-player games have no mummy curse (there is nobody to curse); a Cursed Room still curses ✅

### Karak: Regent (expansion) ✅
- **Switch in the lobby** ("Karak: Regent expansion"): the deck gets 18 more tiles (6 Battle Arenas, 4 Cursed Rooms, 8 rooms) and the bag 6 Giant Bats and 2 Skeleton Mages. The four Regent heroes can only be picked with the switch on (see Heroes); the Lord Karak variant is a second switch shown under it ✅
- **Battle Arena** (a corridor) ✅
  - The first hero to enter it on their own turn chooses another hero; that hero's figure is moved onto the arena and they fight a duel
  - Both sides choose their spells and the Warlock's Sacrifice before any die is rolled (the challenger first, then the summoned hero, who sees the challenger's choice); then the challenger rolls, then the summoned hero; the Warrior may still reroll after his roll. The strength to beat is the other hero's total
  - All combat skills apply, a cursed hero has none; the Oracle gets +1 when the arena is her first step or when she is summoned
  - Higher total wins (a hero who wins ties wins an equal total; both or neither → tie). The winner takes one item or treasure of the loser (a full pack takes it in exchange, the winner's own item stays on the arena — or keeps its own items and leaves the taken one on the arena: the loser loses it all the same); the loser loses 1 HP; a tie changes nothing
  - Both figures stay on the arena; the duel ends the challenger's turn, like any fight
  - Only a hero fit to fight is summoned: not one lying unconscious or waiting to reincarnate. A virtual challenger picks the rival with the most treasures; a human summoned during a virtual player's turn decides for himself (the AI's turn waits); only a seat that is away is played automatically
  - The duel is not optional: nobody walks on before it is over, and a rival never chosen when the turn ends is picked (the most treasures) and the duel settled at once; an unfinished duel is settled at the turn end (no consumables, best loot automatically)
  - The arena activates once; later entries are a normal corridor; a retreat or a push-back onto it does not activate it
- **Cursed Room** (a corridor) ✅
  - Every hero entering it rolls a die: 1–3 → Curse of Karak, 4–6 → safe; an already cursed hero does not roll; being pushed back onto it after a lost fight rolls again; several rolls in one turn (a Sprint) are each shown
  - There is one curse token: a curse from a Cursed Room takes it from whoever carried it (that hero is free again), as a mummy curse does. A healing fountain or the Portal of Healing lifts it
- **Monsters**: Giant Bat (6 HP) drops Dark Thorn; Skeleton Mage (11 HP) drops Frost Fist ✅
- **Spells**: a Warrior losing his last HP to a Dark Thorn or in a duel reincarnates at the nearest discovered fountain automatically ✅

### Lord Karak variant (Karak: Regent) ✅
- **Switch in the lobby** ("Lord Karak variant", only with the expansion on; the server refuses it in a base game)
- **When**: the fifth Lord Karak room (Battle Arena or Cursed Room) is placed — Lady Lorraine's Listening counts. Placing comes before entering, so when the fifth room is an arena the transformation happens first, then the duel. Once per game, only with 2+ players
- **The weakest hero** (every player, virtual ones included):
  1. the lowest treasure value (the same number that scores the game)
  2. tie → the weakest weapons: token strength of every dagger, sword and axe carried (Hanna's daggers in spell slots count; Sword Master does not)
  3. tie → the fewest other items (keys and spells)
  4. tie → a die for each tied hero; the lowest of them roll again until one is lowest
- **Transformation**: fully healed (from 0 HP too; Valduk's heal limit does not apply), any curse removed (mummy token and Cursed Room), loses his own abilities and gains those of every other hero in the game (a cursed rival still lends his). With 2 players he keeps his own abilities as well. When no other hero is in play he has no abilities
- **Otherwise unchanged**: 4 moves, can be cursed again (losing all those abilities until healed), collects treasure; the game still ends with the dragon. A virtual Lord Karak uses all the abilities he took over, like any AI hero
- **Shown** as a one-time dialog with the standings and in the action log; his card shows the Lord Karak portrait, 👑 and the hero he was

### Stunning/Defeat ✅
When a player's HP reaches 0:
1. Player is stunned/defeated ✅
2. Player remains on their current tile ✅
3. Skip their next turn ✅
4. Regenerate to 1 HP at the start of the skipped turn ✅
5. Exception: a hero with Reincarnation (see Heroes) is not stunned ✅

### Heroes ✅
All hero skills below are switched off while the hero carries the Curse of Karak (see above).
Heroes are dealt at random, as in the board game: the lobby switch **Random heroes** is on by default and every player gets a different hero (the Karak: Regent heroes only with the expansion). With the switch off you pick your hero yourself (or none — no special skills) and the AI is dealt one of the others. ✅
- The AI player is always dealt a hero and is drawn with it (portrait, figure, badge) ✅
- The AI uses its skills: the odds of a fight count Backstab, Combat Training, Bear Attack, Berserk, Farseeing, Sword Master, Double Attack, Sacrifice and its spells; after the roll it rerolls (Double Attack) only when spells cannot win, spends the cheapest spells (+ Sacrifice, never the last HP) only when they turn the fight into a victory; it reincarnates at the fountain nearest to its goal, sets Valduk's heal limit to 4, leaves unwanted loot with Tireless, keeps going after an Unstoppable 6, sneaks past fights it would rather avoid (Stealth), walks through walls (Astral Walking), listens at a new room holding a bad fight (Listening), uses Lightning Attack on the most valuable monster it beats often enough (never at 1 HP), Magic Swap onto a rival on a fountain when low or next to a dragon worth fighting, Sprint to a goal 5–8 moves away ✅
- AI spells: every Dark Thorn on its turn (a rival at 1 HP who would be knocked out first, otherwise the one with the most treasure), Portal of Healing to the nearest fountain at 2 HP or less or when cursed ✅

#### Horan, the Warrior ✅
- **Double Attack** ✅
  - In any combat, after the first roll (dice + weapons) the Warrior may reroll both dice once ✅
  - The first roll is discarded and costs no HP, even if it was a loss ✅
  - The second roll must be accepted; spells can still be used on top of it ✅
  - Rerolling is optional; a winning first roll is final ✅
- **Reincarnation** ✅
  - When the Warrior loses his last HP he does not fall unconscious ✅
  - Instead he chooses any discovered healing fountain tile (the starting tile always has one) and teleports there ✅
  - All of his HP are replenished (his own maximum) ✅
  - Knocked out on his own turn (a fight, a duel he started) he chooses the fountain; knocked out off his turn (summoned to a duel, hit by a Dark Thorn) he goes to the nearest one at once ✅
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
  - The step into the fight is one of the 4 moves when the turn goes on; the usual limit of 4 moves applies to the rest of the turn ✅

#### Lord Xanros, the Warlock ✅
- **Sacrifice** ✅
  - After the dice are rolled (when the weapons-only roll is a tie or a loss) the Warlock may pay 1 HP for +1 strength, once per combat ✅
  - The HP is paid before the result is resolved, whatever the outcome; the bonus is shown separately from item bonuses ✅
  - He may pay his last HP: he then falls unconscious and skips his next turn (restoring 1 HP), exactly like after a lost fight ✅
  - A fight already won with weapons only is final, no sacrifice is offered ✅
- **Magic Swap** ✅
  - As the first action of his turn the Warlock may trade places with any other hero: he moves to that hero's tile, the other hero to his ✅
  - It costs all 4 moves, so the turn ends right after the swap ✅
  - The ability of the new tile still applies: a reward lying there may be picked up (a locked chest still needs a key) and a healing fountain heals when the turn ends there ✅
  - Arriving by swap never starts a battle and nothing else about the swapped hero changes; a hero standing on an undefeated monster (a Thief sneaking) cannot be swapped with ✅

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
  - Whenever the Oracle draws a token (opening a room), two are drawn from the bag instead of one ✅
  - She chooses which token is placed on the room; the other one goes back into the bag at a random position ✅
  - The choice must be made before moving; a choice left unmade when the turn ends keeps the first drawn token ✅
  - With a single token left in the bag it is a normal draw ✅
  - A virtual Oracle chooses automatically: a chest or treasure first, otherwise the weakest monster ✅

The next four heroes come with the Karak: Regent expansion: the lobby shows them only with the switch on and the server refuses them in a base game. Their active skills are used from the sidebar. ✅

#### Valduk, the Barbarian ✅
- **Berserk** ✅
  - +1 strength with exactly 3 or 4 HP, +2 with 1 or 2 HP; the HP he has when the dice are rolled counts (monster fights and duels) ✅
  - He chooses how many HP a heal restores: a "heal up to N" setting in the sidebar (default: full). Fountains, battle returns onto a fountain and the Portal of Healing heal him up to N, never below his current HP; waking up from a stun still gives 1 HP; while cursed he heals fully ✅
- **Tireless** ✅
  - After winning a monster fight, if the loot still lies on the tile, he may leave it there and continue his remaining moves ✅
  - The step into the fight now counts as one of the 4 moves (a move onto a monster is otherwise never counted); the loot cannot be picked up later in that turn ✅
  - Also after a guarded chest (the Fallen's): it is not collected for him, he may leave it; whoever picks it up later needs no key. The dragon's ruby is always collected (the game ends) ✅

#### Baron Markul, the Battle Mage ✅
- **Sword Master**: a sword has strength 3 in his hands (not while cursed) ✅
- **Lightning Attack** ✅
  - As the first action of the turn, for all 4 moves: teleport onto any tile with an undefeated monster and fight it ✅
  - Win: the usual reward, then the turn ends ✅
  - Draw or loss (HP is lost as usual): he stays on the monster's tile and chooses where to retreat — an opened neighbouring tile reachable through a door and free of monsters; with none, back to the tile he teleported from. A fountain there heals and a Cursed Room rolls, like after any lost fight. The turn ends after the retreat; a retreat never chosen goes to the first option ✅

#### Hanna, the Acrobat ✅
- **Dagger Throwing** ✅
  - A dagger goes into a free weapon slot first, otherwise into a free spell slot; with every slot full the replacement choice offers weapons and spells ✅
  - Every dagger she carries (weapon or spell slot) adds +1 and is never used up; daggers in spell slots are never offered as spells ✅
  - While cursed the daggers stay in the spell slots but add nothing, and no new dagger goes there ✅
- **Sprint** ✅
  - Declared as the first action of the turn: up to 8 moves this turn, over discovered tiles only (no tile is picked or placed) ✅
  - Entering a monster's tile still starts a fight ✅
  - Cursed by a Cursed Room on the way, she falls back to the normal 4 moves (the turn ends at once if 4 or more were spent) ✅

#### Lady Lorraine, the Ranger ✅
- **Bear Attack**: every die showing 1 counts as 6 (not while cursed); for a Lord Karak who also has Combat Training the 1 stays a 6 and is not rerolled ✅
- **Listening** ✅
  - Right after placing a tile next to her she may listen at that tile (only that one) instead of entering: one move, the tile's token is drawn, she stays where she is ✅
  - Entering that tile later is a normal move (a monster there is fought) ✅
  - A Battle Arena or Cursed Room explored this way is not triggered ✅
  - She may listen several times per turn, as long as moves remain ✅


### Healing Fountains ✅
- Special tiles that restore all HP ✅
- Heal only when the turn ends there (a player may also walk on) ✅


## Victory Conditions ✅
1. **Game End**: When any player defeats the final boss ✅
2. **Winner**: Player with the most Victory Points; equal top scores share the victory (each counts a win in the Hall of fame) ✅
3. **Victory Points Sources**:
   - (The project doubles the book's points: a chest or the Fallen's treasure is 1 VP in the book, the ruby 1.5)
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
- `MAX_PLAYERS_COUNT` = 5
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
