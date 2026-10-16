# Implementation Plan: Online play, part 2 — tables, a waiting room, networked games with push

Branch: feature/online-lobby (created from feature/online-accounts @ e565fb4; the chain is merged to main together)
Created: 2026-10-16

## Original Request
Online play: unobtrusive sign-in, a lobby, networked multiplayer, Hall of fame by heroes and by users (У нас нет логина и лобби сейчас никакого. И вообще сетевой игры. Я имею в виду, давай сделаем их. …) — part 2 of 3 (Делай)

## Settings
- Testing: yes.
  - PHPUnit for the table rules, the publisher and the server-side AI driver.
  - `online-table.spec.ts`: two browser contexts play one networked game. The implementer runs it with every topic spec.
- Logging: standard.
  - `table.created`, `table.joined`, `table.started`, `table.left` at info.
  - Publish failures at warning (the game goes on without push).
  - `ai.server_turn` at info, with game id and action count.
- Docs: yes (CLAUDE.md, README, `fe/e2e/README.md`, `.env` keys).

User answers (2026-10-16):
- A table has 2–5 seats (default 4), chosen by the host, with options Regent / Lord Karak / random heroes.
- In the waiting room each player picks a free hero, unless heroes are random.
- The host may seat the AI on free seats, and starts the game once at least two seats are taken.
- In a networked game **the server plays the AI's turn itself**. It sends the actions to everyone, and each browser plays them back as the AI playback does today. "Against the AI" in one browser stays as it is.
- **The opponent's moves show live**: the tile is laid, the figure walks, a fight shows as a short plaque with the result, and a chronicle line is added.
- **An opponent who takes too long** (added mid-planning: "Надо еще предусмотреть вариант, когда соперник долго не ходит"): the turn timeout and the AI standing in move from part 3 into this part, because the server-side AI driver is built here. Part 3 keeps the Google binding.

## Requirements Reconciliation
Authority: user answers > part 1 plan (`feature-online-accounts.md`) > CLAUDE.md > existing code.

| Decision | Source | Verification |
|---|---|---|
| **A table is a game in status `lobby`** with three new `Game` fields, set by a new command `CreateTable` (factory beside `create`): `online` (bool), `hostAccountId` (uuid), `seats` (2–5). Migration via diff. `CreateGame` (one browser against the AI) is unchanged and keeps `online=false`. | existing `GameStatus::LOBBY`, `Game::addPlayer` | PHPUnit `TableTest` |
| **Table rules** in `Game`: seats are capped at `seats`; a joining account gets one seat (joining twice is idempotent); `AddAiToTable` is host only, one AI per call, up to `seats`; `LeaveTable` for a non-host frees the seat (the host leaving closes the table: status finished, no winner, reason `abandoned`); `StartTable` is host only, needs ≥ 2 seats, and every human seat needs a hero unless `randomHeroes` (AI seats are dealt one as today); then the existing start (`GameStarted`, turn order roll). | user | PHPUnit, every rule |
| **Hero choice in the waiting room**: the existing `POST /api/game/pick-hero` (`PickHero`) is guarded by the seat guard (playerId is the caller's seat); a hero taken by another seat is refused (the existing `Game` check); Regent heroes only with `regent`. | existing code | PHPUnit |
| **API** `src/src/Api/Table/…`, invokable actions with DTOs, all needing an account (401 otherwise): `POST /api/tables` → `{gameId, playerId}`; `GET /api/tables` (open: online, lobby, a free seat) → host name, seats taken/total, options, `isMine`; `GET /api/tables/{id}`; `POST /api/tables/{id}/join` → `{playerId}`; `POST /api/tables/{id}/ai`; `POST /api/tables/{id}/leave`; `POST /api/tables/{id}/start`. | user; API pattern | curl smoke + e2e |
| **Push: Mercure.** New `mercure` service (`dunglas/mercure`) in `compose.yml` and `compose.prod.yml`. nginx proxies `/.well-known/mercure` to it (SSE: buffering off). Subscribers are anonymous: topics are game ids (unguessable uuids) and payloads carry no secrets. The publisher JWT key goes in `.env` (`MERCURE_URL`, `MERCURE_JWT_SECRET`); `lcobucci/jwt` is already a dependency. | user | docker up, curl subscribe |
| **Publisher**: `src/src/Infrastructure/Push/MercurePublisher` (curl POST, HS256 JWT, 2 s timeout, errors logged and swallowed). Topics: `karak/game/{gameId}` and `karak/tables`. | — | PHPUnit with a fake transport |
| **What is published**: a `kernel.terminate` listener `PublishGameChange`, after a 2xx `POST /api/game/*` or `/api/tables/*`, publishes `{type: 'game.changed', gameId, playerId, path, turn}`; table changes also go to `karak/tables`. The server's AI turn (below) publishes `{type: 'ai.turn', gameId, playerId, actions}` with the same action list the browser AI playback consumes today. | user: live | e2e |
| **The server plays the AI** in an online game: after a published change, if the current player is an AI seat and the game is online and in progress, `ServerAiDriver` runs `SmartVirtualPlayer::executeTurn` in the same terminate phase (looping while the next player is AI too, capped at 5 turns), then publishes `ai.turn`. Offline games keep the browser-driven AI. | user | PHPUnit with a fake player; e2e |
| **Start screen**: two actions under the name. "Descend against the AI" (as now) and "Open a table". The second opens a small form: seats 2–5, the same option switches, Create. Below them, "Open tables": a list (host, seats `2/4`, Regent mark, Join), refreshed from `karak/tables`. Texts are in en/ru/pl, in the Torchlight look. | user | e2e; screenshots |
| **Invite link** `/table/{gameId}`: with no name, the name card first; then join and enter the waiting room. A full or started table shows a plaque ("This table is full / already playing", back to the start). | user | e2e |
| **Waiting room** = the game page while `status === 'lobby'` and `online`: a centred plaque with the seats (name, hero portrait or "choosing…", AI marks, empty seats); the hero roster for my seat (if not random); invite link with Copy; host: "Seat the AI", "Start" (enabled at ≥ 2 seats and all heroes chosen); others: "Waiting for {host} to start"; Leave. Everything updates from `game.changed`. | user | e2e |
| **My seat in an online game**: `currentPlayerId` = the seat whose `externalId` is my account id (from `GET /api/account/me` and the game state; the localStorage keys are no longer the source in online games). No hot-seat switching (`checkStunnedPlayersAndSwitch`, `autoSwitchPlayer` skip online games). No browser-driven AI (`checkAndHandleVirtualPlayerTurn` skips online games). | recon | e2e |
| **Live opponent**: on `game.changed` from another seat → `loadGameData(false)`. The existing tile dissolve and figure movement then show the change; a new fight action of that seat shows a short notice plaque with the result line (`describeAction` from the chronicle); on `ai.turn` → the AI playback (`useAiTurnPlayback`) with the sent actions. A small "connection lost" chip shows while the EventSource is reconnecting; on reconnect the page reloads the state. | user: live | e2e |
| **Turn time limit**: a table option chosen by the host: 1 / 2 / 5 minutes / none (default 2). `Game` stores `turnSeconds`; every turn of an online game gets a `deadline` (start + limit), shown in the state as `turnDeadline`. | user (late) | PHPUnit |
| **Who notices the deadline**: a `turn-watch` console command (`app:turn-watch`, a loop every 5 s, in its own docker service `turnwatch` in dev and prod compose) finds online in-progress games whose deadline passed, and lets the AI play that turn for the human seat (`ServerAiDriver` with that seat). It marks the seat `away`, publishes `ai.turn` with `standIn: true`, and moves the game on. While a seat is `away` the AI plays each of its turns at once, and a chronicle line says "{who} is away — the AI plays for them". | user | PHPUnit (deadline query, away flag); e2e with a 1-minute limit lowered by a test-mode override |
| **Coming back**: any request from the seat's account (a guarded POST, or opening the game page, which calls `POST /api/game/{id}/present`) clears `away`; the AI stops at the next turn of that seat, and the seat keeps its hero, pack and position. | user | PHPUnit; e2e |
| **On screen**: the turn banner shows a quiet countdown for the acting player (mm:ss, ember under 20 s), and others see "{who} — 0:42". An away seat wears an "away" mark in the party rail. The waiting room shows the time limit. | user | e2e; screenshots |
| **Seat guard**: unchanged; table actions check the account themselves. Test-mode games stay offline and unguarded, so all topic specs keep working. | part 1 | all specs |

## Commit Plan
- **Commit 1** (tasks 1–3): "feat(game): tables — online games with seats, a host, AI seats and a start rule"
- **Commit 2** (tasks 4–6): "feat(push): Mercure hub, game-change events, the server plays the AI in online games"
- **Commit 3** (tasks 7–9): "feat(fe): open a table, the list of open tables, invite links and the waiting room"
- **Commit 4** (tasks 10–12): "feat(fe): networked play — my seat from the account, the opponent's moves live; e2e and docs"
- **Commit 5** (tasks 13–15): "feat(game): a turn time limit for online tables, the AI stands in for an away player"

## Tasks

### Phase 1: Tables in the domain
- [x] Task 1: `Game` fields `online`, `hostAccountId`, `seats`; the `CreateTable` factory; the `AddAiToTable`, `LeaveTable` and `StartTable` commands with handlers and events; the seat cap and the start rule. Migration via diff. Logs `table.*`.
- [x] Task 2: The table API in `src/src/Api/Table/` (create, list, get, join, ai, leave, start) with DTOs; account required; join reuses `AddPlayer` with the account. Open-table listing via DBAL (online, lobby, free seats, newest first, limit 50).
- [x] Task 3: PHPUnit `TableTest` (seat cap, idempotent join, host-only AI/start, ≥ 2 seats, heroes chosen unless random, the host leaving closes the table) and API curl smoke.

### Phase 2: Push and the server's AI
- [x] Task 4: Docker:
  - `mercure` service in `compose.yml` and `compose.prod.yml`;
  - nginx `location /.well-known/mercure` with `proxy_buffering off` and `proxy_read_timeout 1h`, in the dev and prod configs;
  - `.env` / `.env.dist` keys.

  Verify with `docker compose up -d mercure` and a curl subscription.
- [x] Task 5: `MercurePublisher` (+ interface for tests) and the `PublishGameChange` terminate listener. PHPUnit with a recording fake.
- [x] Task 6: `ServerAiDriver` (in `src/src/Game/AI/`): online + in progress + current player AI → `SmartVirtualPlayer::executeTurn`, loop ≤ 5, publish `ai.turn`. Called from the terminate listener after publishing. PHPUnit with fakes; a guard against re-entry (the AI's own sub-requests must not trigger the driver).

### Phase 3: Tables in the browser
- [x] Task 7: `fe/src/services/tables.js`, `fe/src/services/push.js` (EventSource wrapper: subscribe(topic, handler), reconnect state), the start screen: "Open a table" form and the open tables list (live through `karak/tables`).
- [x] Task 8: Route `/table/:id` (join by link → waiting room; full/started plaque); the router guard leaves `/game/:id` as is.
- [x] Task 9: Waiting room in `GameView` (a component `fe/src/components/game/TableRoom.vue`): seats, hero roster for my seat, invite link with Copy, host controls, Leave; live through `karak/game/{id}`.

### Phase 4: Networked play, e2e, docs
- [x] Task 10: `GameView` online mode:
  - my seat from the account;
  - no hot-seat switching and no browser AI in online games;
  - subscribe to the game topic: `game.changed` → reload + a fight notice; `ai.turn` → AI playback;
  - the reconnect chip.
- [x] Task 11: `fe/e2e/tests/online-table.spec.ts`. Two browser contexts:
  - A opens a table (2 seats), B sees it in the list and joins through it;
  - both pick heroes, A starts;
  - A places a tile, and B sees the tile and the chronicle line without a reload;
  - B ends a turn, and A sees it;
  - one table with an AI seat: the server plays the AI turn and both see its playback;
  - invite link for a full table → the plaque.

  Then run all topic specs.
- [ ] Task 12: Docs: CLAUDE.md (tables, push, server AI, online mode), README (how to play online, Mercure in the stack), `fe/e2e/README.md`.

### Phase 5: An opponent who takes too long
- [ ] Task 13: Add the following, with a migration via diff:
  - `Game::turnSeconds` (table option 60/120/300/null);
  - `GameTurn::deadline`;
  - `Player::away`;
  - the `present` endpoint (`POST /api/game/{id}/present`; any guarded POST also clears `away` for the caller's seat);
  - the state fields `turnDeadline` and `players[].away`.

  PHPUnit.
- [ ] Task 14: The `app:turn-watch` command (loop: overdue online turns → mark away, `ServerAiDriver` plays the seat, publish), plus the `turnwatch` docker service in dev and prod; in the test mode the deadline can be set short (`setup-game` / table option `turnSeconds: 5` allowed only in dev/test). Logs: `turn.overdue`, `seat.away`, `seat.back`. PHPUnit for the overdue query and the away/back rules.
- [ ] Task 15: Frontend:
  - the countdown in the turn banner;
  - the "away" mark in the party rail;
  - the time-limit option in the table form;
  - texts in en/ru/pl.

  e2e: a 5-second test limit: B does not move, the AI plays B's turn, B is marked away; B acts and the mark is gone.
