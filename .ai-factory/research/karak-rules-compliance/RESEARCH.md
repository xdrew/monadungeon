# Research: Karak game mechanics vs the rules — full compliance audit

Updated: 2026-10-17 12:45
Status: active
Index: [INDEX.md](INDEX.md)

## Active Summary (input for /aif-plan)
<!-- aif:active-summary:start -->
Topic: Full audit of the implemented game mechanics (backend `src/src/Game`, frontend `fe/src`) against the official Karak rules (base rulebook `a4-karak-rulebook.pdf`, Karak: Regent rulebook `Karak Regent.pdf` + digest `.ai-factory/references/karak-regent-rules.md`) and the project's own `rules.md`.
Goal: A prioritised list of rule deviations, server-side rule-enforcement gaps and documentation drift, each with evidence (file:line) and a proposed fix, ready to be turned into fix plans.
Scope: In — setup and components, tiles, movement and turn end, combat, items and inventory, spells, curse, HP, scoring, the 6 base heroes, the 4 Regent heroes, Battle Arena, Cursed Room, Lord Karak, AI rule use, frontend gating. Out — visuals, audio, online infrastructure (except where it lets a client bypass a rule).
Constraints: Official rulebooks win over `rules.md`. The duel flow "both sides choose spells/Sacrifice before any roll, the Warrior reroll after the roll" is a deliberate user decision from an earlier session (not a defect), although the Regent book reads "the entering player evaluates his strength and decides…, then the summoned one" (`Karak Regent.pdf` p.1). The automatic Swordsman 1-reroll is a documented project choice (book: "can reroll"). The doubled VP scale (chest/Fallen 2, ruby 3 = book 1 / 1.5) is equivalent. Fixes need PHPUnit + the topic e2e specs; migrations only via `doctrine:migrations:diff`.
Requirements:
- R1 (high, server trust): the server must enforce every rule the UI enforces, because online seats and the AI (`VirtualPlayerApiClient` uses the same HTTP API) can send any request. Verified gaps: a MOVE refused by the turn still moves the hero (F-01); PickItem trusts the client's position (F-02); `/game/inventory-action` "replace" builds an item from client JSON (F-03). Reported gaps: inventory replace has no slot-category check (F-04, 2 keys possible); monster-fight finalize is not idempotent (F-05, partly verified: the resolved guard is duel-only; a repeat after a loss costs another HP); `selectedConsumableIds` is not filtered to consumables (F-06, a weapon counts twice and is deleted; a cursed Hanna can use slot daggers).
- R2 (high/medium, rule deviations in play; verified: F-07 server side, F-12 slice and 2 gates; the rest reported by the audits, to be confirmed by a failing test): picking up equipment or unlocking a chest does not end the turn on the server (F-07, `TurnAction::shouldEndTurn` has PICK_ITEM commented out, `UNLOCK_CHEST`/`PICK_UP_EQUIPMENT` are never recorded; the AI's chest path likely keeps walking); a 5th step is possible while a pickup is pending (F-08); a room can be placed and not entered (F-09, reveals the token and skips the fight); Unstoppable fights never spend the step that started them (F-10, extra moves and free refights); a Warrior challenger who reincarnates after losing a duel keeps his turn (F-11); deck is a random 88-of-99 slice (89 tiles instead of 80, 2 teleport gates instead of the 4 in the code's own comment, Regent rooms counted twice) (F-12); the bag is trimmed/padded to the room count, so real tokens are dropped or random extras (3rd Fallen, 11th chest) appear (F-13); a dropped item overwrites an item already on the tile (F-14); Tireless button offered after a spell-won chest fight (now intended, DEC-8) and the finalize response reports `finalTotalDamage: 0` (F-15); the AI double-counts its spells in fight odds (F-16).
- R3 (low): see the Low findings table (F-17…F-31).
- R4 (documentation): `rules.md` drift listed in "rules.md drift" (D-01…D-16).
Decisions:
- DEC-1: The duel's "prepare before the roll" flow stays (user decision); `rules.md` must describe it instead of the old roll-then-decide text.
- DEC-2: The Lord Karak trigger on the 5th placed Lord Karak room stays: the Regent book literally says "all five Lord Karak rooms" while the expansion has 10 such tiles (6 arenas + 4 cursed rooms); "the fifth discovered" is the only workable reading.
- DEC-3: "Weakest hero = fewest treasures" is satisfied by comparing treasure value, because every treasure is worth the same before the dragon ends the game.
- DEC-4: Lightning Attack's retreat to an adjacent open tile matches the book ("may choose in which direction to retreat … only onto already open fields", p.3).
- DEC-5 (user, 2026-10-17; was Q1): there is ONE curse token. A Cursed Room curse moves the single token to the entering hero and frees the previous holder (whether the mummy slayer or an earlier room gave it), like a mummy curse does. `roomCursedPlayerIds` (several cursed heroes at once) goes away; an already cursed hero still does not roll. Evidence for the current model: `src/src/Game/GameLifecycle/Game.php:161, 681-702, 1025-1043, 1073-1092`.
- DEC-6 (user, 2026-10-17; was Q2): a duel ends the challenger's turn (after the loot is taken or the result is a tie/loss), by analogy with the base rule "after a fight with a monster (no matter the result)"; the Regent book does not say. Today the challenger keeps walking unless he lost and is left at 0 HP without reincarnating (`src/src/Game/Duel/Duel.php:725-734`). This also resolves F-11 (a reincarnated Warrior challenger's turn ends) and makes F-24 (walking away during the prepare phase) a must-block.
- DEC-7 (user, 2026-10-17; was Q3): an unconscious hero (0 HP, skipping a turn) or a Warrior awaiting reincarnation cannot be summoned to a duel: leave them out of the rival candidates (`src/src/Game/Field/Field.php:468-485, 2928-2931`); with no eligible rival the arena is only activated, as with no other players today.
- DEC-8 (user, 2026-10-17; was Q5; book support: digest `karak-regent-rules.md:19` "if he defeats a monster and does not take the loot, he may continue"): Valduk's Tireless applies to a monster-guarded chest too (the Fallen's treasure): he may leave it on the tile and keep moving, instead of the automatic collection (`src/src/Game/Field/Field.php:1872-1898`, which stays for every other hero and for a Valduk who does not choose Tireless). A chest whose guard was defeated is loot, not a locked chest: anyone who later steps onto that tile may pick it up WITHOUT a key (the pickup ends his turn, like any treasure). Today PickItem demands a key for every `chest` (`src/src/Game/Field/Field.php:2034-2062`), so the key check must apply only to chests that never had a guard (`guardHP === 0`, already encoded by `Item::isLocked()`, `src/src/Game/Item/Item.php:280-289`). Exception by inference: the dragon's ruby is not leavable, because the game ends the moment the dragon is defeated and "the hero who defeated the beast will get its treasure" (base rulebook). DEC-8 also removes the cause of F-15's Tireless button bug (the button then becomes correct for a guarded chest); the `finalTotalDamage: 0` part of F-15 stays. `rules.md:226` ("Not available when the reward was a guarded chest collected automatically") must be replaced (see D-10).
- DEC-9 (user, 2026-10-17; was Q4): a Cursed Room curses in a single-player game too (as the code already does, `src/src/Game/GameLifecycle/Game.php:681-695`); only the mummy curse has no target there. `rules.md`'s "single-player games have no curse" must say "no mummy curse".
- DEC-10 (user, 2026-10-17; was Q6): the duel is mandatory: an arena choice left unmade when the turn ends auto-picks the rival (the same rule as the AI: the most treasures by count, a tie going to the first in seating order, `src/src/Game/Field/Field.php:2955-2959`) among the eligible ones (DEC-7), instead of dropping `pendingArena` (`src/src/Game/Field/Field.php:2295-2297`); the duel is then settled automatically like any unfinished duel at turn end.
- DEC-11 (user, 2026-10-17; was Q7): equal top scores are a shared win: every player with the highest score is a winner (results screen, Hall of fame and the players' leaderboard count each of them). Today one `winnerId` is the first key after `arsort` (`src/src/Game/GameLifecycle/Game.php:956-988`, winner at :977-981); with equal scores this is, by inference from PHP 8's stable sort, the earliest seat in `getPlayers()` order.
Risks:
- DEC-8 changes the AI's chest handling (`src/src/Game/AI/SmartVirtualPlayer.php:312, 413-419` use `guardHP`/`isLocked()`; its Tireless loot choice) and the guarded-chest branches of `fe/src/components/game/BattleReportModal.vue:476-577`; a defeated guard's chest left on a tile becomes key-free loot for every hero.
- DEC-11 turns the single `Game::$winnerId` into a set of winners: the API state, GameView's result texts, the leaderboard (`src/src/Game/Leaderboard/`) and the account Hall of fame must all follow (`GameEnded::$winnerId`, `src/src/Game/Leaderboard/UpdateLeaderboardOnGameEnd.php:25, 48`); likely a schema change via `doctrine:migrations:diff`.
- DEC-10: an auto-picked duel at turn end is settled automatically, so a summoned human makes no choices; `Field::onTurnEnded` and the e2e arena scenarios that expect "no duel" must follow. DEC-9 needs only the rules.md text (D-11).
- DEC-5 changes the curse model the UI shows (party rail, skills panel, CursedRoomScene "already cursed" and the curse-transfer scene) and a migration drops/stops using `roomCursedPlayerIds`; the e2e "cursed room" scenario in `regent-mechanics.spec.ts` must follow.
- DEC-6 changes the duel flow the AI pause/resume relies on (`aiPausedForDuel`, `ServerAiDriver`): the challenger's turn ends after the duel instead of resuming.
- Changing turn-end on PICK_ITEM touches Unstoppable, Tireless, Magic Swap reward pickup and the AI loop; e2e specs depend on the current client-driven endTurn.
- Fixing deck/bag composition changes game length and every scripted test-mode scenario that relies on deck size; test mode must keep its scripted sequences.
- Removing `/game/inventory-action` "replace" breaks the AI (`SmartVirtualPlayer.php:3711, 3908`) and the client fallback `fe/src/utils/inventoryUtils.js:90`, which must move to PickItem first.
Open questions: none left (Q1–Q7 answered → DEC-5…DEC-11).
Success signals: Every F-01…F-16 has a regression test (PHPUnit for the server rule, topic e2e where the UI changes); crafted API requests for F-01…F-06 are refused; a full game against the AI shows no extra moves after a chest; deck/bag counts asserted by a test; `rules.md` matches the code and the rulebooks.
Next step: `/aif-plan full` for R1 + R2 + DEC-5…DEC-11, including F-24, which DEC-6 makes required (server rule enforcement, deviations, the rule decisions). The rest of R3 and R4 can follow as a second, smaller plan.
<!-- aif:active-summary:end -->

## Sources and method

- Base rulebook: text extracted from `a4-karak-rulebook.pdf` (8 pages, text layer). Key rules quoted below.
- Regent rulebook: `Karak Regent.pdf` is a scan without a text layer; pages 1–4 (rules) were viewed as images on 2026-10-17 to settle the open points of the digest `.ai-factory/references/karak-regent-rules.md`.
- Code: five read-only audits (setup/flow, combat/items/curse, base heroes, Regent heroes/Lord Karak, Arena/Cursed Room), each reading the real code paths. Findings marked **verified** were re-read in the code by the coordinator; the rest are reported with file:line by the audits and should be confirmed by a failing test before fixing.
- Verdict scale: MATCH / DEVIATION / MISSING / UNCLEAR. Severity: high = changes game outcomes or allows cheating; medium = noticeable in some situations; low = edge case or cosmetic.

### Rulebook passages the findings rely on

- Turn end (base, "End of a Turn"): "once a player has moved 4 times; after a fight with a monster (no matter the result); after unlocking and collecting a treasure; after collecting an equipment; after healing at the HF".
- Entering an undiscovered zone (base): "the player takes one dungeon tile, connects it to the tile they are leaving and moves their hero onto the new tile".
- Treasure and key (base): "If a treasure was drawn and the player owns a key, they can collect the treasure and discard the key. In this case the player's turn is over."
- Equipment slots (base): "A player can only place the equipment into its respective slot … only have two weapons, three spells and one key at the same time."
- Contents (base): "80 dungeon tiles (including the starting card)", "43 monster tokens (1 dragon, 2 Fallen, 12 skeleton turnkeys, 3 skeleton kings, 5 skeleton warriors, 8 giant rats, 4 giant spiders, 8 mummies)", "10 locked treasure chest tokens".
- Warrior Reincarnation (base): "he teleports to any tile with a HF, where all his HPs are replenished. However, his turn ends anyway."
- Swordsman Unstoppable (base): "If at least one of the dice yields a 6 during combat, the Swordsman can continue his turn afterwards."
- Battle Arena (Regent p.1, Russian edition): the entering player chooses whom to invite and moves that figure to his own; "First the player who entered the arena evaluates his strength. He must decide whether to use some of his spells and/or hero abilities. Then the summoned player evaluates his strength likewise." Winner takes "a treasure or any other item or spell"; loser loses 1 life; tie — nothing; both figures stay.
- Cursed Room (Regent p.2): "every player must roll the dice on entering … 1–3: the Curse of Karak falls on the hero — he takes the curse token and may continue the remaining steps … 4–6 avoided. A hero who already has the curse token does not roll." Components list (p.1) has no curse tokens.
- Lord Karak (Regent p.4): "Lord Karak enters the game when all five Lord Karak rooms (Battle Arenas and Cursed Rooms) are discovered"; weakest = fewest treasures → lowest total weapon strength → fewest other items → lowest die.

## Findings — server trust (R1)

| ID | Finding | Where | Sev | Status | Fix proposal |
|----|---------|-------|-----|--------|--------------|
| F-01 | A MOVE refused by the turn (after PICK_TILE/ROTATE, turn ended…) keeps the new position: the turn error is swallowed after `playerPositions` was updated and `PlayerMoved` dispatched. With a picked tile a hero can walk for free and then place the tile anywhere next to him. | `src/src/Game/Movement/Movement.php:219-269` (`catch (\RuntimeException)`) | high | verified | Validate the turn action before moving (or rethrow so the transaction rolls back). |
| F-02 | PickItem takes `$command->position` and never compares it with the hero's position (fetched but used only for the event): loot, dropped items and chests can be taken from any tile. | `src/src/Game/Field/Field.php:1991-2006`; `src/src/Api/Game/PickItem/Action.php:33` | high | verified | Refuse unless position = hero position (or the won battle's tile). |
| F-03 | `/game/inventory-action` "replace" builds an `Item` from client JSON (name, type, treasureValue, itemId) and puts it into the inventory: a scoring cheat. Used by the AI and a client fallback. | `src/src/Api/Game/InventoryAction/Action.php:59-110`; `src/src/Game/AI/SmartVirtualPlayer.php:3711, 3908`; `fe/src/utils/inventoryUtils.js:90` | high | verified (endpoint) | Remove the endpoint; take items only from the field through PickItem. |
| F-04 | `replaceInventoryItem` does not check the slot category: a key can replace a sword (2 keys, 4 spells). Duels check it (`Duel.php:373`). | `src/src/Game/Player/Player.php:889-948` | medium | reported | Require the same category (Hanna's dagger exception). |
| F-05 | Monster-fight finalize has no "resolved" guard (only duels do): a repeated finalize after a loss takes another HP; an old fight can be re-run with spells into a win; FIGHT_MONSTER logged twice. | `src/src/Game/Battle/Battle.php:367-380, 505-528` | medium | partly verified (guard is duel-only) | Mark the battle resolved; afterwards accept only the loot pickup; check turn and player. |
| F-06 | `selectedConsumableIds` is not filtered to consumables: a weapon id adds its damage again and is then deleted; a cursed Hanna can use slot daggers; a Portal/Thorn id is spent for +0. | `src/src/Game/Battle/Battle.php:404-470`; `src/src/Game/Player/Player.php:559-574` | medium | reported | Intersect with `getConsumableItems()` having damage > 0 (and respect the curse for slot daggers). |

## Findings — rule deviations in play (R2)

| ID | Finding | Where | Sev | Status | Fix proposal |
|----|---------|-------|-----|--------|--------------|
| F-07 | Collecting equipment / unlocking a chest does not end the turn on the server. `PICK_ITEM` is commented out of `shouldEndTurn`; `UNLOCK_CHEST` and `PICK_UP_EQUIPMENT` are never recorded. Only the browser calls endTurn; the server even allows exploring after fight→pickup. The AI's chest path continues its loop after the pickup (to be confirmed by a test). | `src/src/Game/Turn/TurnAction.php:66-77`; `src/src/Game/Field/Field.php:2112-2127`; `fe/src/views/GameView.vue:~5675`; `src/src/Game/AI/SmartVirtualPlayer.php:2705-2735` | high | verified (server) / reported (AI) | PICK_ITEM ends the turn on the server, except the Unstoppable / Tireless / Magic Swap flows. |
| F-08 | A 4th move onto a pickable item defers the turn end; the next MOVE is applied (position + PlayerMoved, even into a fight) before the turn ends — a 5th step. Non-modal HUD plaques make it reachable from the UI. | `src/src/Game/Turn/GameTurn.php:214-235, 277-297`; `src/src/Game/Movement/Movement.php:222, 276-292`; `src/src/Game/Field/Field.php:1679` | medium | reported | Refuse MOVE / PICK_TILE when no moves are left or a pickup is pending; empty `moveTo` then. |
| F-09 | A room may be placed and not entered (END_TURN or walking elsewhere): its token is revealed and its fight skipped. Only the frontend chains placeTile → movePlayer. Only Lady Lorraine's Listening should allow it. | `src/src/Game/Field/Field.php:780-925`; `fe/src/utils/fieldUtils.js:534-605` | medium | reported | After PLACE_TILE allow only the entering MOVE, LISTEN or the Fateweaver choice. |
| F-10 | A step onto a monster never counts as a move. With Unstoppable (a 6) the Swordsman keeps more moves than the book allows (a fight on step 4 leaves a 5th step) and re-entering after a loss/tie is free, so every 6 is another free refight. A Lord Karak Oracle can get Farseeing twice the same way. The test hides it. | `src/src/Game/Movement/Movement.php:233`; `src/src/Game/Turn/GameTurn.php:330, 398`; `src/tests/Game/Turn/GameTurnTest.php:840` | medium | reported | Count the fight step when the turn continues (Unstoppable, Tireless already does). |
| F-11 | A Warrior challenger who loses a duel on his last HP reincarnates and keeps his turn; the book: "his turn ends anyway". | `src/src/Game/Duel/Duel.php:726-734` | medium | reported | End the challenger's turn also when he reincarnated. |
| F-12 | Deck: 99 tiles are built (61 rooms, 34 tunnels, 2 fountains, 2 gates), shuffled and sliced to 88 + start = 89; the book has 80 (the code's own comment `Deck.php:73-86` lists the real mix incl. 4 gates). Regent adds its 18 on top while its 8 rooms are already in the 61. Random slicing can drop gates/fountains (teleporting often useless). | `src/src/Game/Deck/Deck.php:70-147, 196-215`; `src/src/Game/GameLifecycle/CreateGame.php:20` | medium | verified (slice, 2 gates) | Fixed base composition from the comment (79 + start, no slicing); Regent adds exactly 18. Test mode keeps scripted sequences. |
| F-13 | Bag trimmed or padded to the room count: real tokens can be removed, or random extras added (3rd Fallen, 11th chest), changing the VP in play. | `src/src/Game/Bag/Bag.php:119-184` | medium | reported | Exactly the book's tokens (+ Regent bats/mages) once the deck is fixed. |
| F-14 | One item per tile: a drop onto a tile that already holds an item overwrites it (guard commented out). Reachable via duel exchanges on an arena. | `src/src/Game/Field/Field.php:2146-2193` | medium | reported | A stack per tile, or refuse / move the drop. |
| F-15 | Tireless button shown after a spell-won fight whose reward is a guarded chest (the chest is then auto-collected and `/tireless` fails, the turn stays open); the finalize response returns `finalTotalDamage: 0`, so the client computes the result from 0. | `fe/src/components/game/BattleReportModal.vue:620`; `src/src/Api/Game/FinalizeBattle/Action.php:~62`; `fe/src/views/GameView.vue:5535` | medium | reported | Button part superseded by DEC-8 (Tireless offered for a guarded chest; no auto-collect when chosen); return the real total and result. |
| F-16 | AI fight odds double-count spells: `calculateItemDamage()` already includes fireballs/frost fists and they are passed again (also when `useSpells` is false). Skews fight choice, Lightning targets, dragon decision. | `src/src/Game/AI/SmartVirtualPlayer.php:626, 1668, 1689, 5343` | medium | reported | Pass `calculateWeaponDamage()`. |

## Findings — low severity (R3)

| ID | Finding | Where | Fix proposal |
|----|---------|-------|--------------|
| F-17 | Reincarnation restores `MAX_HP`, not the player's `maxHp` (test-mode only). | `Player.php:277` | Use `$this->maxHp`. |
| F-18 | Reincarnation after a duel or a Dark Thorn goes to the nearest fountain automatically; a human gets no choice ("any tile with a HF"). | `Duel.php:659`; `Field.php:2628, 2673` | A pending reincarnation choice for humans. |
| F-19 | An auto (AI/away) duel side never uses Double Attack, and its preset counts "weapons + 7", ignoring Berserk, Bear Attack, Combat Training. | `Battle.php:228, 244`; `Duel.php:557` | Use `BattleOdds::expectedRoll` + `skillBonus`; reroll like `HeroTactics::afterRoll`. |
| F-20 | Combat Training rerolls a 1 before Bear Attack sees it (Lord Karak with Swordsman + Ranger loses a sure 6). | `Battle.php:1022-1045` | Apply Bear Attack first / skip the reroll with `bearAttack()`. |
| F-21 | Listen may target any adjacent tile right after a placement, not only the tile just placed. | `Field.php:1193-1201` | Check the last PLACE_TILE position. |
| F-22 | Hanna cursed mid-Sprint keeps 8 moves; the board then offers placements the turn refuses. | `GameTurn` sprint flag; `Field.php:1736` | Decide one behaviour and make both checks agree. |
| F-23 | AI Sprint path ignores monsters/fountains/arenas/cursed rooms on the way. | `SmartVirtualPlayer.php:4219-4270` | Exclude feature tiles from the sprint path. |
| F-24 | The challenger may walk off or end the turn during his own prepare phase (the duel is then settled with no spells). | `Movement.php:161-164` | Block moves and END_TURN until the duel is settled (required by DEC-6, part of the first plan). |
| F-25 | Markul's retreat onto an arena explored only by Listening starts a duel that is settled automatically at turn end. | `Field.php:1226-1252, 2804-2815, 2908` | Skip `enterArena` on a battle return, or document. |
| F-26 | `roomEvent` is one slot: two Cursed Rooms in one Sprint show only the last scene. | `Field.php:390-391` | Queue room events for the UI. |
| F-27 | Stealth: a Thief may end on an undefeated monster's tile; a Warlock can then swap onto it without a fight. | `Movement.php:188-196, 302-371` | Refuse a swap target on an undefeated monster, or document. |
| F-28 | `placeTile` trusts the client's `requiredOpenSide` when the place is directly reachable. | `Field.php:780-865`; `Tile.php:136-155` | Always check the side facing the hero. |
| F-29 | Starting-player tie goes to the first seat instead of a reroll (documented). | `Game.php:1045-1065` | Optional reroll among tied players. |
| F-30 | `GetActivePlayers` has two handlers (`Player/Handler/GetActivePlayersHandler.php:25` returns Players, `Game.php:318` returns Uuids); `Field.php:2930` expects Uuids. | as listed | Confirm which handler the bus resolves; remove the other. |
| F-31 | Dead code: `Battle/PlayerMovedHandler.php` (not registered; would start a second battle if it were); duplicate stun checks `Field.php:302-327, 614-660`. | as listed | Delete. |

## Confirmed matches (no action)

Monster strengths and drops (rat 5 dagger, spider 6 portal, mummy 7 bolt, turnkey 8 key, warrior 9 sword, king 10 axe, Fallen 12 chest, dragon 15 ruby; bat 6 Dark Thorn, mage 11 Frost Fist); bag token kinds and counts before trimming; 5 HP, 5 players, start tile with a fountain; clockwise order; tile connection and rotation; first-discovery spawn; teleport gates (inactive alone, 1 move); fountains heal only by ending the turn there, lift the curse, start-tile loss heals; loss/tie retreat; win loot; spells removed from the game; Magic Bolt and Frost Fist after the roll, several per fight; Portal of Healing on anyone, no move, no turn end; Dark Thorn; key max one and spent from the game; unconscious → skip a turn, wake at 1 HP; Curse of Karak (slayer picks another player or leaves the token; skills off via `Player::getSkills()`, which every hero check, the API flags and the AI use); dragon ends the game at once; all base hero skills (Double Attack, Reincarnation, Backstab, Stealth, Magical Affinity, Astral Walking, Sacrifice, Magic Swap, Unstoppable logic, Farseeing, Fateweaver); Regent heroes (Berserk, heal limit, Tireless (except a guarded chest, DEC-8), Sword Master, Lightning Attack, Dagger Throwing, Sprint, Bear Attack, Listening); Lord Karak reveal, ranking, transformation, 2-player exception, arena order; Battle Arena (first entry, once, rival moved without tile effects, loot incl. treasure, loser −1 HP and stun, tie, both stay, abilities apply, Oracle +1 when summoned or on her first step, Listening does not activate); Cursed Room (roll on every entry, already cursed no roll, active all game, pushed back rolls again, remaining moves continue; except the several-curses model, DEC-5); Regent components only with the flag.

## rules.md drift (R4)

| ID | rules.md says | Reality / book | Line |
|----|---------------|----------------|------|
| D-01 | `MAX_PLAYERS_COUNT = 4` | 5 (`Game.php:55`, book: 5 stands) | rules.md:283 |
| D-02 | Healing fountains "end the player's turn immediately" | You may heal only by ending the turn there; otherwise move on | rules.md:255 |
| D-03 | Tie: "no damage, monster remains" | The hero also returns to the previous tile | rules.md:63 |
| D-04 | Fireball "+1 damage to next attack" | +1 in the current fight, after the roll, several per fight | rules.md:82 |
| D-05 | VP 2 / 3 without explanation | Book 1 / 1.5 (doubled scale); tie rule now set by DEC-11 (see D-16) | rules.md:259-262 |
| D-06 | Duel: "the challenger rolls and decides first … then the summoned hero rolls" | Both choose before any roll (DEC-1) | rules.md:113 |
| D-07 | Duel winner takes "one item they have room for" | A full pack takes it in exchange | rules.md:115 |
| D-08 | "A human summoned during a virtual player's turn is rolled automatically" | The AI turn pauses and the human decides | rules.md:117 |
| D-09 | Unstoppable "the usual limit of 4 actions applies" | Not true in the code (F-10) | rules.md:181 |
| D-10 | Tireless "Not available when the reward was a guarded chest collected automatically" | Available for a guarded chest; a defeated guard's chest needs no key (DEC-8) | rules.md:226 |
| D-11 | "Single-player games have no curse" | No mummy curse; a Cursed Room still curses (DEC-9) | rules.md:107 |
| D-12 | "A choice left unmade when the turn ends means no duel" | The rival is auto-picked among the eligible ones (DEC-10, DEC-7) | rules.md:118 |
| D-13 | Duel: "the challenger's turn goes on unless knocked out" | A duel ends the challenger's turn (DEC-6) | rules.md:116 |
| D-14 | Cursed Room: "Several heroes may be cursed at once; the mummy token is still single" | One curse token; a Cursed Room moves it to the entering hero (DEC-5) | rules.md:122 |
| D-15 | "Keys: Required to unlock treasure chests"; Magic Swap "(a chest still needs a key)" | Only a chest that never had a guard needs a key (DEC-8) | rules.md:87, :192 |
| D-16 | Winner: "Player with the most Victory Points" | Equal top scores are a shared win (DEC-11) | rules.md:260 |

## Sessions
<!-- aif:sessions:start -->
### 2026-10-17 11:10 — Initial full audit
What changed: Created the bundle: 16 high/medium findings (F-01…F-16), 15 low (F-17…F-31), 7 open rule questions, 9 rules.md drift items, 4 decisions.
Key notes: Base rulebook text extracted from the PDF; Regent rulebook pages 1–4 viewed as images to settle the digest's open points (Arena order, curse token, "five" rooms, Lightning retreat). F-01, F-02, F-03, F-07 (server side) and F-12 (slice, 2 gates) were re-read in the code; the rest come from the read-only audits with file:line. Coherence gate passed: the Active Summary marks verified vs reported findings and keeps Q1–Q7 open.
Links (paths): `rules.md`, `a4-karak-rulebook.pdf`, `Karak Regent.pdf`, `.ai-factory/references/karak-regent-rules.md`, `src/src/Game/`, `fe/src/`
### 2026-10-17 11:40 — User answers Q1–Q3
What changed: Q1 → DEC-5 (one curse token, a Cursed Room moves it), Q2 → DEC-6 (a duel ends the challenger's turn), Q3 → DEC-7 (no unconscious or reincarnation-pending rival). Added the risks of DEC-5/DEC-6 and default proposals for Q4–Q7 in Next step.
Key notes: DEC-6 absorbs F-11 and turns F-24 into a required block. Coherence gate re-run on the updated summary.
Links (paths): `src/src/Game/GameLifecycle/Game.php`, `src/src/Game/Duel/Duel.php`, `src/src/Game/Field/Field.php`
### 2026-10-17 12:05 — User answers Q5
What changed: Q5 → DEC-8: Valduk may leave a monster-guarded chest with Tireless; a chest whose guard was defeated is picked up by anyone without a key. Removed Q5 and its default from Next step.
Key notes: Code read: PickItem requires a key for every `chest` (Field.php:2034-2062); the auto-collect of guarded chests is Field.php:1872-1898. The dragon's ruby stays auto-collected (the game ends at once) — an inference from the base rulebook, not a user statement.
Links (paths): `src/src/Game/Field/Field.php`, `fe/src/components/game/BattleReportModal.vue`
### 2026-10-17 12:20 — User answers Q4, Q6, Q7
What changed: Q4 → DEC-9 (Cursed Room curses in solo games), Q6 → DEC-10 (an unmade arena choice auto-picks the rival), Q7 → DEC-11 (shared win on equal scores). No open questions left; added the DEC-11 risk.
Key notes: DEC-10 reuses the AI's rival rule (most treasure) and respects DEC-7's eligibility.
Links (paths): `src/src/Game/GameLifecycle/Game.php`, `src/src/Game/Field/Field.php`, `src/src/Game/Leaderboard/`
### 2026-10-17 12:30 — Coherence fixes for DEC-8…DEC-11
What changed: F-15's fix and the R2 line now follow DEC-8; DEC-8 cites the digest and `Item::isLocked()`, clarifies that a picked-up guard's chest ends the picker's turn; Confirmed matches qualify Tireless; added the DEC-8 risk and rules.md drift D-10…D-12 (Tireless, solo curse, unmade arena choice + shared win).
Key notes: The gate after DEC-8 failed on three silent contradictions (F-15 fix, rules.md:226 not in R4, Tireless listed as a full match); all three corrected here.
Links (paths): `rules.md`, `src/src/Game/Item/Item.php`, `src/src/Game/AI/SmartVirtualPlayer.php`
### 2026-10-17 12:45 — Final coherence fixes
What changed: DEC-9…DEC-11 moved under Decisions; DEC-10/DEC-11 evidence made precise; drift rows D-13…D-16 added (duel turn, curse token, keys for guarded chests, shared win) and D-12 split; F-24 marked required by DEC-6 and included in the first plan; Cursed Room match qualified by DEC-5; DEC-10 and DEC-11 risks completed.
Key notes: Integrity gate passed throughout; the coherence gate found these as silent contradictions between the new decisions and rules.md/R3, all corrected as the gate specified.
Links (paths): `rules.md`, `src/src/Game/Field/Field.php`, `src/src/Game/Leaderboard/UpdateLeaderboardOnGameEnd.php`
<!-- aif:sessions:end -->
