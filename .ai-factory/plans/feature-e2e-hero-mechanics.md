# Implementation Plan: e2e specs for every hero skill and the mechanics the showcase spec does not cover, with screenshots of every screen

Branch: feature/e2e-hero-mechanics (created from feature/torchlight-lobby @ b533190+; the chain is merged to main together)
Created: 2026-10-16

## Original Request
e2e tests for every hero's skills and the other mechanics the main showcase spec does not cover (Давай ты спроектируешь и напишешь несколько других e2e тестов, которые протестируют умения каждого героя и все остальные механики, не покрытые нашим главным тестом)

Follow-up in the same request: "Заодно сделаешь скриншоты каждого интерфейса и критически их оценишь"

## Settings
- Testing: yes. The deliverable is Playwright specs; the backend test-mode extension gets PHPUnit tests. **The implementer runs the new specs** (user, 2026-10-16: "Запускаешь сам" — overrides the CLAUDE.md rule for these specs only; the showcase spec stays the user's) through the docker playwright setup, and fixes them until green.
- Logging: standard. Every spec step logs one `console.log('[spec] …')` line, as the showcase spec does. The backend test mode logs nothing new.
- Docs: yes. Rewrite `fe/e2e/README.md`, which is stale and names specs that do not exist. Update the CLAUDE.md "Frontend E2E Testing" section (it is local and gitignored).

User answers (2026-10-16):
- **Separate specs by topic.** The showcase spec `two-player-game.spec.ts` stays as it is.
- **Screenshots through the specs.** Each spec saves every screen it reaches to `fe/test-results/screens/<spec>/<NN>-<screen>.png`, at desktop size and, for the key screens, at phone width. After the user's run the implementer reads them and writes a critical review.
- **The backend test mode may be extended**, dev/test only, with PHPUnit tests.

## Requirements Reconciliation
Authority:
1. User answers.
2. `rules.md` (every rule is marked ✅ there, so every rule is an e2e candidate).
3. Existing code.

What the showcase spec already covers (not repeated here):
- tile placement and movement; teleportation gates; the fountain tile;
- battles won, lost and drawn; stun and the skipped turn; fireball as a consumable;
- the full-pack swap after a fight and on the field; duplicate key; leaving an item and picking it up later;
- Warrior Double Attack and Reincarnation; Thief Stealth and Backstab;
- the dragon kill ending the game.

**Test-mode limits found** (`src/src/Game/Testing/*`, `Field::getNextDiceRoll`):
- Dice come from one global queue, with no per-player sequence.
- The tile and bag order is fixed.
- Tile orientation, position, current HP, inventory and curse cannot be preset.

Inventory and HP are the gap that matters: without them, every hero test has to play 3–6 turns to collect items. The extension below adds them and nothing else. Positions still come from play.

### Spec map

Hotseat with two human players unless noted. Each spec has one `test()` per hero or mechanic, so a failure stays local.

| Spec | Test | What it proves | UI hooks |
|---|---|---|---|
| `pages.spec.ts` | lobby | Switches: random heroes (on by default), Regent, Lord Karak (disabled while Regent is off); the roster with and without Regent; the selected hero card; a reload keeps the choices | `random-heroes-toggle(-input)`, `regent-toggle(-input)`, `lord-karak-toggle(-input)`, `hero-<type>`, `hero-card`, `hero-card-random` |
| | language | en, ru, pl through `.language-switch__ring` / `__item`; `<html lang>` changes; a reload keeps the choice | `.language-switch__code` |
| | rules book | Contents links scroll to `#overview … #tips`; the Regent blocks are present | `rules-regent`, `rules-lord-karak`, `.rules-back` |
| | Hall of fame | Table or empty state; sorting by victories / games; back link | `.leaderboard-table`, `th.victories.sortable`, `.leaderboard-back` |
| | 404 | Unknown URL shows 404; the home button leads to the lobby | `.not-found h1`, `.home-button` |
| | vs AI | Lobby → "Descend against the AI" → game; the AI turn plays back; ×½ / ×1 / ×2 switch the active speed; the AI's moves appear | `.primary-button`, `.ai-playback`, `.speed-btn.active` |
| `base-heroes.spec.ts` | swordsman | Combat Training note after a 1 is rerolled; Unstoppable: a 6 keeps the turn going (the button says continue, the hero moves again) | `combat-training-note`, `unstoppable-note` |
| | warlock | Sacrifice chip turns a loss into a win, and HP drops by 1; Magic Swap ring, then a click on the rival's figure swaps positions (checked through the API) | `sacrifice-hp`, `sacrifice-note`, `magic-swap`, `magic-swap-banner`, `player-figure-<id>` |
| | wizard | Magical Affinity: a fireball is used and kept (`.consumable-kept`, still in the pack); Astral Walking: a move marker across a wall, and the move succeeds | `affinity-note`, `.move-marker` |
| | oracle | Farseeing +1 on the first move; Fateweaver: two tokens, one is picked, the other goes back to the bag | `farseeing-note`, `fateweaver-dialog`, `fateweaver-item-0/1` |
| `regent-heroes.spec.ts` (`regent: true`) | barbarian | Berserk +2 at 1–2 HP (preset hp); heal limit select (ring popover), then healing at a fountain stops at the limit; Tireless: win, leave the loot and keep moving | `berserk-note`, `heal-limit`, `tireless-continue` |
| | battle_mage | Sword Master: a preset sword counts 3 in the breakdown; Lightning Attack ring, then a target monster, then a fight; after a fight that is not won, a retreat choice | `lightning-attack-btn`, `lightning-target-<x,y>`, `lightning-retreat-note`, `retreat-<x,y>` |
| | acrobat | Dagger Throwing: a found dagger goes into a spell slot when the weapons are full (preset); Sprint ring, then the badge, then more than 4 steps | `sprint-btn`, `sprint-badge`, `.spell-item` |
| | ranger | Bear Attack: a 1 counts as 6; Listening ring, then the next placement is a listen (the room's monster shown before entering) | `bear-attack-note`, `listen-toggle` |
| `mechanics.spec.ts` | curse | A won mummy fight offers the curse choice; the rival is cursed (rail badge, curse token, the skill notes disappear); ending a turn on a fountain lifts the curse | `curse-choice`, `curse-player-<id>`, `curse-token`, `.cursed` |
| | portal of healing | A preset teleport spell is cast to a fountain (the hero moves there, the spell is gone) | `.spell-item`, `.healing-fountain-marker`, `portal-target-<id>` |
| | chests | Without a key: the missing-key plaque appears and the turn ends. With a preset key: the chest opens and the treasure is counted | `.missing-key-dialog`, `.item-pickup-overlay`, `.btn-pickup` |
| | not offered | Full pack plus a find that is not better: no plaque, a notice instead; a click on the item still opens it | `action-notice`, `.item-pickup-overlay` |
| | rotation | The ghost tile rotate button changes the orientation (the placed tile has the chosen doors, checked through the API) | `.ghost-rotate-btn` |
| | reload | A fight left open survives a reload (`.battle-overlay` is back with the same dice) | `.battle-overlay` |
| | endgame | A preset axe and fireballs beat the dragon at once; the finished banner; the results overlay (win banner, winner row, "you"); Hall of fame lists the winner | `.game-finished-banner`, `.view-results-button`, `.leaderboard-overlay`, `.lb-winner` |
| `regent-mechanics.spec.ts` (`regent: true`) | arena | Entering a Battle Arena tile: opponent choice, duel scene against the rival, duel result, loot choice | `arena-marker`, `duel-opponent-<id>`, `duel-result`, `duel-loot-<itemId>`, `duel-dismiss` |
| | cursed room | Entering a cursed room: the roll notice; on 1–3 the curse badge | `cursed-room-marker`, `action-notice` |
| | giant bat → dark thorn | Win the bat, take the Dark Thorn, cast it at the rival (HP −1) | `dark-thorn-<id>`, `dark-thorn-banner`, `dark-thorn-target-<id>`, `dark-thorn-confirm` |
| | skeleton mage → frost fist | A preset frost fist (+2) turns a loss into a win | `.consumable-row .item-chip.selectable` |
| | lord karak (`lordKarak: true`) | Discovering the Lord Karak room shows the reveal cinematic; the rail marks Lord Karak and the merged skills | `lord-karak-modal`, `lord-karak-who`, `lord-karak-close`, `.lord-karak` |

Dice, tile and bag sequences per test are worked out at implementation from the domain code:
- dice per fight, plus rerolls for Combat Training, Double Attack and Bear Attack;
- the cursed-room die;
- Fateweaver drawing two tokens.

Each test's sequence is written next to it as a comment table, as in the showcase spec.

### Screenshots
- `fe/e2e/utils/screens.ts` with `snap(page, spec, name, { phone? })`.
  - Waits for fonts (`document.fonts.ready`) and for animations to settle (a fixed 400 ms after the target selector is visible).
  - Saves the full-viewport PNG. With `phone`, it also saves a copy at 390×844 (viewport resized, re-snapped, size restored).
- Screens covered across the specs:
  - lobby (all three option states), rules, Hall of fame, 404, each language;
  - game start;
  - fight scene: rolling, win, loss, draw, spell selection, duel, curse choice;
  - each plaque: item found, full pack, missing key, Stealth, Fateweaver, retreat, reincarnation, opponent choice, duel result;
  - each skill ring and its banner;
  - AI playback;
  - Lord Karak cinematic; finished banner; results overlay; journal.
- Review after the user's run: one critical pass over every screenshot (layout, overflow, contrast, wording, consistency with Torchlight, phone width). It is delivered as a list of findings, each with a severity. The fixes are agreed with the user before they are made.

## Commit Plan
- **Commit 1** (tasks 1–2): "test(testing): test mode presets a player's HP and pack"
- **Commit 2** (tasks 3–4): "test(e2e): screenshot helper, shared setup; pages spec"
- **Commit 3** (tasks 5–6): "test(e2e): base and Regent hero skill specs"
- **Commit 4** (tasks 7–8): "test(e2e): mechanics and Regent mechanics specs"
- **Commit 5** (task 9): "docs(e2e): README for the specs"
- Task 10 (screenshot review) runs after the user's e2e run; its fixes are commits of their own.

## Tasks

### Phase 1: Test mode

- [x] Task 1: Preset HP and pack per player
  - `src/src/Game/Testing/PlayerTestConfig.php`: add `?int $hp` and `list<string|array> $inventory = []`.
    - `$hp` is the current HP, ≤ maxHp.
    - Each `$inventory` entry is an item type (`'sword'`, `'fireball'`, `'teleport'`, `'key'`, `'frost_fist'`, `'dark_thorn'`, `'chest'`) or `{type, name?, treasureValue?}`.
  - `src/src/Api/Testing/SetupTestGame/Request.php`: map the new fields.
  - `SetupTestGameHandler`: after `StartGame`, for each player dispatch `AddItemToInventory` with a guard-defeated item, using the same `Item` factory as the bag. Then set HP with a new test-only `Player::setTestHp()` guarded like `setTestMaxHp`.
  - Unknown types throw `\InvalidArgumentException`; the API turns that into a 422.
  - Must not touch prod: the handler's existing APP_ENV guard stays first.
  - Logging: none beyond the existing message-bus log.
- [x] Task 2: PHPUnit
  - `src/tests/...Testing/SetupTestGameHandlerTest.php`, or extend the existing test if there is one:
    - the preset pack lands in the right categories;
    - `hp` is applied;
    - an unknown type is refused;
    - a full category in the preset is refused, the same way the domain refuses it.
  - `fe/e2e/utils/test-game-setup.ts`: `PlayerTestConfig` gets `hp?`, `inventory?`; `tileSequence` typed `(string | TileSpec)[]` with `TileSpec {orientation?, room?, features?}`.

### Phase 2: Shared e2e pieces and the pages spec

- [x] Task 3: `fe/e2e/utils/screens.ts` and `fe/e2e/utils/hotseat.ts`
  - `snap()` as described under Screenshots.
  - `hotseat.ts`:
    - `openGameAs(page, gameId, playerId)`: session, navigation, `waitForGameLoad`.
    - `switchTo(page, playerId)`: session plus reload, as the showcase spec does it.
    - `readyAll(request, gameId, ids)`.
    - `apiState(request, gameId)`.
    - `expectHp(request, gameId, playerId, n)`.
  - Reuse `GameHelpers` (`placeTile`, `movePlayer`, `continueBattle`, `endTurn`, `chooseStealth`, `handleInventoryReplacement`, `moveWithItemDialog`). Do not copy them.
  - Add only what is missing:
    - `rotateGhost(page, n)` on `.ghost-rotate-btn`;
    - `clickRing(page, testid)`;
    - `expectNotice(page, re)`.
  - `GameHelpers.placeTile` ignores x/y, so specs plan their tile sequences around "the first available place". This must be documented in `hotseat.ts`.
- [x] Task 4: `fe/e2e/tests/pages.spec.ts`: the six tests in the map, with a snap for every screen and phone snaps for the lobby, rules and the game start.
  - The vs-AI test is the only one without test mode. It asserts no particular moves, only that the playback bar appears, the speed buttons switch `.active`, and the turn comes back to the human (`h3` "Turn: 2").

### Phase 3: Hero skills

- [x] Task 5: `fe/e2e/tests/base-heroes.spec.ts`: Swordsman, Warlock, Wizard, Oracle, as in the map.
  - Each test gets its own `setupGame`: hero, `hp` and `inventory` presets, and a dice/tile/bag script with a comment table.
  - Assertions use the UI hook and the API state (HP, position, pack).
  - Snaps: every note, ring, banner and plaque.
- [x] Task 6: `fe/e2e/tests/regent-heroes.spec.ts`: Barbarian, Battle Mage, Acrobat, Ranger, with `regent: true`, as in the map.
  - Same rules as task 5.
  - The heal-limit popover: select 4, end the turn on a fountain from a preset HP of 1, expect HP 4.

### Phase 4: Mechanics

- [x] Task 7: `fe/e2e/tests/mechanics.spec.ts`: curse, portal of healing, chests, not offered, rotation, reload, endgame, as in the map.
  - Endgame: preset axe + sword + 2 fireballs.
  - The dragon is the first room's monster: a `fourSideRoom` and `dragon` first in the bag.
  - The dice must beat 15 with the bonuses.
  - Then the results overlay and `/leaderboard`.
- [x] Task 8: `fe/e2e/tests/regent-mechanics.spec.ts`: arena, cursed room, giant bat → dark thorn, skeleton mage → frost fist, lord karak, as in the map.
  - Tile names: `battleArena`, `cursedRoom`.
  - Lord Karak needs the room that reveals it. Find from `Game::onLordKarakRoomDiscovered` which tile or feature triggers it.
  - If the test deck cannot express that tile, extend `DeckTile::createFromName` with a `lordKarakRoom` name in this task, and add a PHPUnit case to task 2's test.

### Phase 5: Docs and review

- [x] Task 9: `fe/e2e/README.md` rewritten:
  - the specs and what each covers;
  - how to run one spec: `docker compose … run --rm playwright npm run test:e2e -- --config=playwright.config.docker.ts <spec>`, plus a Taskfile `fe:test:spec` task taking `{{.CLI_ARGS}}`;
  - the test-mode presets;
  - the screenshot folder.

  The CLAUDE.md e2e section is updated the same way. It is local only.
- [x] Task 10: After the user's run, read `fe/test-results/screens/**` and write the critical review, as described under Screenshots. Failures from the run are fixed first, one commit per spec.
