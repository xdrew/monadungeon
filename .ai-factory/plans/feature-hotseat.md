# Implementation Plan: Hot-seat — several players at one screen

Branch: feature/hotseat (created from fix/karak-rules-minor @ 9eac23e; the chain is merged to main together)
Created: 2026-10-18

## Original Request
Hot-seat: несколько живых игроков за одним экраном/браузером (2–4), запуск из лобби отдельной вкладкой/режимом, с опциональными ИИ-местами; передача экрана между игроками в свой ход; опирается на уже существующую логику локального мультиплеера в GameView (secondPlayerId) и e2e-хелперы fe/e2e/utils/hotseat.ts

## Settings
- Testing: yes.
  - PHPUnit for the hot-seat game flag and the seat naming rules.
  - A new topic spec `fe/e2e/tests/hotseat.spec.ts`. The implementer runs it, as with other new topic specs.
- Logging: verbose.
  - Backend: `game.hotseat_seat` at info (gameId, playerId, guest or account).
  - Frontend: `console.info('[hotseat] …')` for the curtain, the screen handover, and the AI seat being driven.
- Docs: yes. Covers CLAUDE.md (an architecture line), `fe/e2e/README.md` (the new spec) and the rules page in-app if it lists game modes.

User answers (2026-10-18):
- The lobby gets a **third tab**, "At one screen" (`lobby-tab-hotseat`), next to "Against the AI" and "Online".
  - It has 2–4 seats. Each seat is a human (name + hero) or the AI.
  - The options (Regent, Lord Karak, random heroes) are shared with the other tabs.
- Between two different humans' turns, a **curtain "Pass the turn"** covers the screen. The next player's pack, skills and choices show only after they press it.
- Tests: yes.
- Branch: commit the current work, then branch from `fix/karak-rules-minor`.

## Requirements Reconciliation
Authority: user answers > CLAUDE.md / rules.md > existing code. Existing behaviour that must not change:
- e2e specs that switch players by rewriting localStorage (`fe/e2e/utils/hotseat.ts`).
- One human against one AI from the "Against the AI" tab.
- Online tables.

| Decision | Source | Verification |
|---|---|---|
| **A hot-seat game is flagged**: `CreateGame::$hotseat` → `Game::$hotseat` (bool, column, migration via `doctrine:migrations:diff`), exposed as `state.hotseat`. Everything new on the frontend (the curtain, following the turn, labels) is gated on this flag. Test-mode games and the AI tab are not flagged, so the existing e2e flow (localStorage switching plus a reload) and the AI game stay as they are. | user; e2e helpers | PHPUnit `HotseatGameTest`; existing specs untouched |
| **Seats**: 2–4 seats, at least one human; the AI seats are added like today (`isAi`, dealt a hero). Backend limit stays `MAX_PLAYERS_COUNT` 5; the 4-seat cap lives in the lobby UI. | user | e2e (2 humans + 1 AI) |
| **Human seat identity**: `POST /api/game/player` for a human seat of a hot-seat game: the **first** human seat takes the caller's account (as today: `externalId`, account name, counts in the players' Hall of fame). **Later** human seats are guests: `externalId` null, `username` from the request (trimmed, 1–24 chars, else `Adventurer N`). They do not appear in the players' board, but the heroes' board counts every seat as today. Non-hot-seat games keep "a human seat always takes the account". | existing `AddPlayer/Action.php`, `UpdateLeaderboardOnGameEnd` | PHPUnit on the action's rule |
| **Seat guard**: guest seats have no `externalId`, so `SeatGuard` lets any caller act for them, which is the existing rule for seats without an account. The account seat stays guarded by the same browser cookie. No guard change. | `SeatGuard.php` | e2e |
| **Who acts on the screen**: in a hot-seat game `currentPlayerId` (and `humanPlayerId`) follow `state.currentPlayerId` whenever the current seat is human. A human seat may act when it is the current player. | user | e2e |
| **AI seats, any number**: the browser drives the AI when the current player has `isAi` (from `players[]`). This replaces the single `localStorage.virtualPlayerId` check in `checkAndHandleVirtualPlayerTurn`, `loadGameData` and the related spots. `virtualPlayerId` stays written for the old single-AI flow, but is no longer the source of truth. | gap found in recon | e2e (two humans + AI: the AI moves) |
| **The curtain**: shown when the current seat is human and differs from the last human revealed on this screen (kept per game in `sessionStorage`). It covers the field controls, the pack, the skills ring and the decision plaques until "I am {name} — show my turn" is pressed. The AI turns between humans play back over the field as today, and the curtain comes after them. A stunned human gets the curtain first, then the stunned plaque. The duel prep or loot of a summoned human during someone else's turn gets the curtain too (the screen hands over mid-turn). | user | e2e: curtain shows, hides the pack, reveals on click |
| **"You" labels**: in a hot-seat game "you"/"(you)" mean the seat whose turn is on screen. At the end, the banner names the winner ("Победа за X") instead of "You win". | existing `isCurrentUserEntry` keyed on `humanPlayerId` | e2e screenshot |
| **Texts**: en/ru/pl via `t()`, terms from the glossary; `npm run i18n:check`. Torchlight look, no emoji. | CLAUDE.md | i18n:check |

Supported combinations (applicable only):

| Seats | Behaviour | Check |
|---|---|---|
| 2 humans | curtain between every turn; both act at one screen | e2e |
| 2 humans + AI (1–2) | AI turns play back; curtain before the next human only when the human changes | e2e (2H+1AI) |
| 1 human + AI (hot-seat tab) | no curtain ever (the same human always); behaves like the AI tab | manual |
| random heroes on/off | off: each human seat picks a free hero in the seat row; on: dealt at join | e2e (off), manual (on) |

## Commit Plan
- **Commit 1** (after tasks 1–2): `feat(hotseat): hot-seat games — the flag and guest seats`
- **Commit 2** (after tasks 3–5): `feat(hotseat): the lobby tab, the turn follows the seats, any number of AI seats`
- **Commit 3** (after tasks 6–9): `feat(hotseat): the pass-the-turn curtain, labels, e2e and docs`

## Tasks

### Phase 1: Backend
- [x] Task 1: **The hot-seat flag.** Add `public bool $hotseat = false` to `CreateGame` (`src/src/Game/GameLifecycle/CreateGame.php`) and to `GameCreated` if the event carries options. Then:
  - Store it on `Game` (`private bool $hotseat = false`, Doctrine column, `isHotseat()`), and generate the migration with `doctrine:migrations:diff` in the php container. Never hand-write it.
  - `POST /api/game` (create action and Request DTO) accepts `hotseat`. It is refused together with `online`, which can't happen through this endpoint anyway.
  - `Api/Game/Get/Response.php` exposes `state.hotseat`.
  - Logging: info `game.created` gains `hotseat`.
  - Files: `CreateGame.php`, `Game.php`, `Api/Game/Create*/…`, `Api/Game/Get/Response.php`, `migrations/`.
- [x] Task 2: **Guest seats.** In `src/src/Api/Game/AddPlayer/Action.php`, a human seat of a hot-seat game:
  - Takes the account when no seat of this game has the account yet.
  - Otherwise becomes a guest: `externalId` null, `username` = trimmed request `username` (1–24 chars), else `Adventurer {n}`, where n is the human seat number.
  - Non-hot-seat games are unchanged.
  - Add `#[Assert\Length(max: 24)]` on `Request::$username`.
  - Logging: info `game.hotseat_seat` {gameId, playerId, guest: bool}.
  - Tests: PHPUnit `src/tests/Api/Game/AddPlayerHotseatTest.php` (or the nearest existing API-test pattern) covers four cases: the first seat takes the account, the second is a guest with its name, a blank name becomes `Adventurer 2`, and a non-hot-seat game keeps the account name. Also a `Game` test: the flag is stored and returned. (depends on 1)
<!-- Commit checkpoint: tasks 1–2 -->

### Phase 2: Lobby and turn flow
- [x] Task 3: **Lobby tab "At one screen".** In `fe/src/views/HomeView.vue`:
  - `LOBBY_TABS` gets `'hotseat'` (`data-testid="lobby-tab-hotseat"`, remembered in `lobby.tab`).
  - A new component `fe/src/components/lobby/HotseatSetup.vue` holds 2–4 seat rows. Each row has a kind (human/AI); a human row has a name input and a hero picker reusing the existing roster/portraits, hidden when random heroes are on. Taken heroes are disabled in the other rows, and Regent heroes need the switch. Rows can be added and removed (min 2, max 4, at least one human). The Start button (`hotseat-start`) is disabled until valid.
  - Start calls `createGame(null, {regent, lordKarak, randomHeroes, hotseat: true})`, then `joinGame` per seat in row order (human: `username`, `hero`; AI: `isAi`), `playerReady` for all, `startGame`, and navigates to `/game/:id`.
  - It stores `currentPlayerId` and `humanPlayerId` (first human) and `humanPlayer:<gameId>`. `virtualPlayerId` gets the first AI, for the old code paths.
  - Add `api.js` support for `hotseat` in `createGame`.
  - Texts in en/ru/pl.
  - Logging: `console.info('[hotseat] creating', {seats})` and errors through the existing notice.
  - Desktop ≥700px high: the page must not scroll (CLAUDE.md lobby rule); the rows fit or scroll inside the panel. (depends on 1, 2)
- [x] Task 4: **Any number of AI seats.** In `fe/src/views/GameView.vue`, the AI turn is driven when the current player `isAi` (from `gameData.players`), not when it equals `localStorage.virtualPlayerId`. This covers `checkAndHandleVirtualPlayerTurn` (the `isAITurn` check, the consecutive-AI loop after a turn), the `isPlayerTurn` AI branch, `loadGameData` auto-detection, `showAiBattleOverlay` fallback position, and the other `virtualPlayerId` reads found by grep.
  - `executeVirtualPlayerTurn` gets the current AI seat id.
  - Keep the single-AI game working unchanged.
  - Logging: `console.info('[hotseat] AI seat plays', id)` when the AI seat is not the stored `virtualPlayerId`. (no dependency; can be done before 3)
- [x] Task 5: **The turn follows the human seats** (hot-seat games only, `state.hotseat`).
  - After every load, when the current seat is human, set `currentPlayerId` and `humanPlayerId` (refs and localStorage) to it.
  - `isPlayerTurn`: a hot-seat game is my turn when the current seat is human and not processing the AI.
  - Remove the hot-seat duty from the legacy `secondPlayerId` swaps (`checkStunnedPlayersAndSwitch`, after-turn switch) by returning early for `state.hotseat`. Their old behaviour stays for unflagged games, so the e2e helpers keep working.
  - Logging: `console.info('[hotseat] screen follows', {from, to})`. (depends on 4)
<!-- Commit checkpoint: tasks 3–5 -->

### Phase 3: The curtain, labels, tests, docs
- [x] Task 6: **The pass-the-turn curtain.** New `fe/src/components/game/HotseatCurtain.vue`, Torchlight look:
  - Hero portrait, "Turn: {name}", and "Pass the screen to {name}".
  - An ember button "I am {name} — show my turn" (`data-testid="hotseat-reveal"`). Root class `hotseat-curtain`.
  - Shown by GameView when `state.hotseat` and the current seat is human and ≠ `sessionStorage['hotseat.revealed:<gameId>']`, and also when a summoned human must prepare or loot a duel during another human's turn, keyed by that seat.
  - While shown it sits over the HUD and the field controls (z-index above the plaques) and blocks input.
  - It waits for the AI playback, the fight scene, the room scenes and the duel outcome to finish, using the existing `tileSceneBusy`-style gate.
  - Reveal stores the seat id and plays a soft `notice` cue.
  - A stunned seat sees the curtain, then the stunned plaque.
  - Texts in en/ru/pl.
  - Logging: `console.info('[hotseat] curtain', {seat})` / `'[hotseat] revealed'`. (depends on 5)
- [x] Task 7: **Labels for the seat on screen.** In hot-seat games:
  - `isCurrentUserEntry` / "you" in the party rail, choices, the HUD headline and the pack owner follow the current human seat, which they already do once `humanPlayerId` follows (task 5). Verify each spot from the recon list and fix the ones that read a stale value.
  - The end banner and the end screen name the winner (`game.overWins`) instead of `overYouWin`. No "you" badge on the end screen.
  - Guest names show everywhere players are named (they come from `username`).
  - (depends on 5)
- [x] Task 8: **e2e** `fe/e2e/tests/hotseat.spec.ts`, using the lobby UI with random heroes off:
  1. Two humans and one AI, with named seats and picked heroes; start.
  2. The curtain shows for seat 1. The pack is hidden until reveal.
  3. Seat 1 plays a turn: place a tile, move, end turn.
  4. The AI plays back, then the curtain shows for seat 2 (the name in the text). Reveal; seat 2's pack and skills are shown.
  5. Screenshots of the curtain and of the lobby tab (`screens.ts` helper, phone too).
  6. The players' Hall of fame entry counts only the account seat. Optional, if a short finish is easy; otherwise a PHPUnit assertion in task 2 covers it.

  Add the spec to the topic list in `fe/e2e/README.md`. The implementer runs it with `task fe:test:spec -- hotseat`, together with `pages`, `base-heroes` and `online` to check the old flows. (depends on 3–7)
- [x] Task 9: **Docs.**
  - CLAUDE.md gets a "Hot-seat" architecture line. It covers: the `CreateGame::$hotseat` flag, guest seats, `HotseatSetup.vue`, `HotseatCurtain.vue`, `sessionStorage['hotseat.revealed:<id>']`, the turn following human seats, AI seats from `isAi`, and the e2e spec.
  - `rules.md`: a line under game modes, if such a section exists.
  - Run `npm run i18n:check` and lint. (depends on 8)
<!-- Commit checkpoint: tasks 6–9 -->
