# Implementation Plan: Online play, part 1 — guest accounts, players who own their seats, the Hall of fame by players and by heroes

Branch: feature/online-accounts (created from feature/e2e-hero-mechanics @ d68b269; the chain is merged to main together)
Created: 2026-10-16

## Original Request
Online play: unobtrusive sign-in, a lobby, networked multiplayer, Hall of fame by heroes and by users (У нас нет логина и лобби сейчас никакого. И вообще сетевой игры. Я имею в виду, давай сделаем их. Надо и по героям, и по юзерам, для этого нужно будет авторизацию приделать какую-то ненавязчивую)

## Settings
- Testing: yes.
  - PHPUnit for the account, the seat guard and the leaderboard queries.
  - The new e2e spec `online.spec.ts` is run by the implementer, as for the topic specs.
  - The showcase spec stays the user's.
- Logging: standard.
  - `account.created`, `account.renamed` at info.
  - Seat-guard refusals at warning, with path, gameId, playerId and accountId.
  - Never log the session token.
- Docs: yes.
  - Update CLAUDE.md: the "No sign-in" line goes; describe accounts and the seat guard.
  - Update README and `fe/e2e/README.md`.

User answers (2026-10-16):
- **Sign-in:** a guest account with a name. On the first visit the server issues a guest account (an HttpOnly cookie) and the player only types a name. Binding the account through Google, to keep it on another device, comes in part 3.
- **Lobby:** a list of open tables plus an invite link. Free seats can go to the AI. This is part 2.
- **Sync:** push from the server (SSE through Mercure, or WebSocket). This is part 2.
- **AI and leaving:** "play against the AI" stays one click. In a networked game a player has a turn timeout; if they vanish, the AI plays for them until they return. This is part 3.
- Split into 3 parts:
  1. accounts, names, the seat guard, the Hall of fame by players and heroes;
  2. lobby and networked games with push;
  3. turn timeout, the AI standing in, Google binding.

## The three parts
| Part | Scope | Depends on |
|---|---|---|
| **1 (this plan)** | Guest account (cookie session, name), the human seat bound to the account (`Player.externalId = accountId`, `username = name`), a server-side seat guard (only the seat's account may act for it; AI turns only for AI seats of a game the caller sits in), the Hall of fame by players (accounts) and by heroes, the name on the start screen | — |
| 2 | Lobby: tables (create, list open ones, join by link, fill seats with AI, the host starts); networked game page (`currentPlayerId` = my seat from the server, no hot-seat switching); Mercure hub in docker, the backend publishes "game changed" after every command, the client reloads and plays back the opponent's actions like the AI's | 1 |
| 3 | Turn timeout per networked game, the AI plays a vanished human's turn (the seat stays theirs, a return takes it back), presence; "keep this account" — bind the guest to Google (OAuth) and sign in on another device | 1, 2 |

## Requirements Reconciliation
Authority: user answers > CLAUDE.md > existing code. CLAUDE.md "No sign-in, no wallets" is superseded by the user (2026-10-16); "no wallets" stays.

| Decision | Source | Verification |
|---|---|---|
| **Account**: `src/src/Account/` (package by feature), Doctrine entity `Account` in schema `account`. Fields: `id` uuid v7, `name` (2–24 chars, trimmed, letters/digits/space/`-_.'`, unique not required), `tokenHash` (sha256 hex of the session token, unique), `createdAt`, `lastSeenAt`. The migration comes from `doctrine:migrations:diff`. | user; DDD layout | PHPUnit `AccountTest` |
| **Session**: the token is 32 random bytes, base64url. It is set as cookie `karak_session`: HttpOnly, SameSite=Lax, Path=/, Secure when the request is https, 400 days. Only its hash is stored. An unknown or absent cookie means anonymous. | security basics | PHPUnit `SessionTokenTest` + API smoke with curl |
| **API**: `POST /api/account/guest {name}` creates a guest and sets the cookie (if the caller already has a valid session it renames instead). `GET /api/account/me` returns 200 `{id, name}` or 204 when anonymous. `PATCH /api/account/me {name}` renames. All are invokable Actions with Request/Response DTOs as elsewhere. | API pattern (CLAUDE.md) | curl smoke; e2e |
| **Who is calling**: `CurrentAccount` resolves the account from the cookie once per request (request attribute), touching `lastSeenAt` at most once a minute. It is a plain service used by the guard and the actions, not the Symfony firewall (the skeleton security config stays as is). | existing code: no authenticators | PHPUnit |
| **Seats**: `POST /api/game/player` with `isAi:false` and a known caller takes `externalId` and `username` from the account, and ignores body values for them. `isAi:true` keeps `username` ("AI Player") and no externalId. An anonymous caller adding a human seat gets **401** (`account.required`), except in the test mode (setup-game creates seats without accounts). | user: the HoF needs users | PHPUnit `SeatGuardTest`, API smoke |
| **Seat guard**: one kernel-request listener for `POST /api/game/*` whose JSON body has `playerId`. It looks the Player up. If the player has an `externalId`, the caller's account id must equal it, else **403** `seat.not_yours`. A player without an externalId (old games, test-mode players) is not guarded. `virtual-player-turn` is allowed only for an `isAi` player, and when that game has a human seat, only for a caller who owns one of its human seats. The `ActionAdapter` duplicate route is removed (route conflict found in recon). | security; recon finding | PHPUnit, curl: another cookie gets 403 |
| **Hall of fame by players**: `UpdateLeaderboardOnGameEnd` keys rows by `externalId` (the account id) and refreshes `username` from the account name. AI players are skipped. Old rows without externalId are left as they are. | user | PHPUnit `UpdateLeaderboardOnGameEndTest` |
| **Hall of fame by heroes**: `GET /api/leaderboard/heroes`, computed with SQL over finished games. It counts per hero: games, victories (winner = that player) and win rate. Every player counts, AI included. It is sorted by victories, with the same sort/limit parameters as the players' board. No new table. | user | PHPUnit on the query with a fixture game; e2e |
| **Hall of fame page**: tabs "Players" / "Heroes" (`?tab=heroes`). Heroes show portrait and name from `fe/src/theme`. The player's own row is marked "you" (by account id: the players' endpoint adds `isYou`). The `sortBy` bug is fixed (the page sent `totalGames`, the API accepts `total_games`). | user; recon | e2e |
| **Start screen**: no account → a small name card ("What shall the dungeon call you?", a random hero-like suggestion such as "Wanderer 42", one ember button). It replaces "Descend" until a name is set, in a plaque, not a modal over the art. With an account → "You: Name" with a pen to rename (inline). The game creation flow sends no username. | user: unobtrusive | e2e |
| **The client**: axios `withCredentials: true` (same origin, harmless). `fe/src/services/account.js` holds `me()`, `createGuest(name)`, `rename(name)`. The name feeds `playerDisplayName` through `username`, which is already the second priority after the hero name. | existing helpers | build + e2e |
| **Existing e2e**: test-mode setup creates seats without accounts, so they stay unguarded and every topic spec keeps working. `online.spec.ts` covers: the name card, the guard (a second browser context gets 403 acting for the first one's seat), and the Hall of fame tabs after a win. | user: tests as usual | the implementer runs all topic specs |
| **i18n**: every new text in en/ru/pl; `npm run i18n:check`. | CLAUDE.md | i18n:check |

## Commit Plan
- **Commit 1** (tasks 1–3): "feat(account): guest accounts with a cookie session and a name"
- **Commit 2** (tasks 4–5): "feat(game): human seats belong to accounts; the server refuses acting for another's seat"
- **Commit 3** (tasks 6–7): "feat(leaderboard): Hall of fame by players and by heroes"
- **Commit 4** (tasks 8–10): "feat(fe): the name on the start screen, the Hall of fame tabs; e2e and docs"

## Tasks

### Phase 1: Accounts
- [x] Task 1: `Account` aggregate in `src/src/Account/`. Contents:
  - `Account` entity with factory `Account::createGuest(name, tokenHash, at)` and `rename(name)`;
  - `AccountName` value object with validation;
  - `AccountRepository`;
  - commands `CreateGuestAccount` and `RenameAccount`, with handlers on the aggregate (`#[Handler]`, `#[FindBy]` as in `Game`);
  - events `AccountCreated` and `AccountRenamed`;
  - DI registration in `src/src/Account/di.php`, following `Game/di.php`.

  Doctrine mapping for schema `account`; migration via diff in the php container. Logs `account.created` / `account.renamed` (id, name length), info.
- [x] Task 2: Session. Contents:
  - `src/src/Account/Session/SessionToken` (generate, hash);
  - `CurrentAccount` service (reads the `karak_session` cookie, finds by hash, caches on the request, touches `lastSeenAt` at most once a minute);
  - `SessionCookie` factory (HttpOnly, Lax, Secure on https, 400 days).

  No token in logs.
- [x] Task 3: API under `src/src/Api/Account/`:
  - `Guest/{Action,Request,Response}` — `POST /account/guest`, sets the cookie;
  - `Me/Get/...` — `GET /account/me`;
  - `Me/Rename/...` — `PATCH /account/me`.

  Error responses through `App\Api\Error`: 422 for an invalid name, 401 when anonymous on PATCH. PHPUnit for the aggregate, name validation and token hashing.

### Phase 2: Seats and the guard
- [x] Task 4: `AddPlayer` API (`src/src/Api/Game/AddPlayer/Action.php`) takes the account for human seats; an anonymous caller gets 401 (test mode exempt). `Player` keeps `externalId`/`username` as today, no schema change. Remove `Api/Game/VirtualPlayerTurn/ActionAdapter.php` (duplicate route) after a grep proves nothing references it.
- [x] Task 5: `src/src/Api/Security/SeatGuardListener.php`, a kernel.request listener with priority after routing:
  - Applies to `POST` under `/api/game/` with a JSON `playerId`. Exempt: `/api/game` (create), `/api/game/player` (join), `/api/test/*`.
  - Looks up the player through the bus (`GetPlayer`).
  - 403 `seat.not_yours` for another's seat; the rules for `virtual-player-turn` are as in the table above.
  - Logs warnings on refusal.
  - PHPUnit `SeatGuardTest` with a fake request.
  - curl smoke: two cookies, the second gets 403 on `end-turn` for the first one's seat.

### Phase 3: Hall of fame
- [x] Task 6: `UpdateLeaderboardOnGameEnd`: key by externalId (account id), refresh the username, skip AI players. `GET /api/leaderboard` returns `isYou` (caller's account id = `external_id`) and accepts `totalGames` as an alias of `total_games`. PHPUnit.
- [x] Task 7: `src/src/Api/Leaderboard/Heroes/{Action,Response}.php`, `GET /api/leaderboard/heroes`: SQL over `player.player` joined to finished `game.game` (`status = 'finished'`, `winner_id`), grouped by hero (null hero → excluded), with `games`, `victories`, `winRate`; `sortBy` victories|games|win_rate. PHPUnit with a finished fixture game (the existing bus tester or a DBAL fixture, whichever the leaderboard tests use).

### Phase 4: Frontend, e2e, docs
- [x] Task 8:
  - `fe/src/services/account.js` and axios `withCredentials`.
  - `HomeView.vue`: the name card when anonymous, "You: Name" with inline rename when signed in; the create flow sends no username.
  - Locale keys in en/ru/pl.
  - Torchlight look (a plaque in the right column, the ember button).
- [ ] Task 9: `LeaderboardView.vue`:
  - tabs Players / Heroes (with `?tab=` in the URL);
  - a heroes table with portrait and name;
  - the "you" mark;
  - the sort fix;
  - phone layout.
- [ ] Task 10: `fe/e2e/tests/online.spec.ts`:
  - the name card, then create, then rename;
  - the guard (a second `browser.newContext()` without the cookie gets 403 acting for the first seat; `request` with the first context's cookies succeeds);
  - after a test-mode dragon win, the Hall of fame tabs. The players' tab lists the name only when the seat has an account. So `setup-game`'s `playerConfigs` get optional `accountId` (the guest created through the API in the same browser context); the seat then gets that account's `externalId` and name. This is dev/test only, extends `PlayerTestConfig`, and has a PHPUnit case.

  Then run all topic specs. Docs: CLAUDE.md (accounts, seat guard, the "No sign-in" line replaced), README, `fe/e2e/README.md`.
