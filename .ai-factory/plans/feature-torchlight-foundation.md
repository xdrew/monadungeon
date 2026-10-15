# Implementation Plan: Torchlight redesign, part 1 — style foundation and the end of the Monad theme

Branch: feature/torchlight-foundation (created from feature/ai-heroes @ bec511f; the chain is merged to main together)
Created: 2026-10-15

## Original Request
редизайн в стиле C, часть 1

## Settings
- Testing: no (no FE unit runner; verification = `npm run build`, ESLint on touched files, PHPUnit for the backend removal, browser screenshots via the host Chrome, the user's e2e run)
- Logging: standard (no new console noise; the backend removal deletes code, the leaderboard handler keeps its existing PSR logger lines)
- Docs: yes (README themes section, CLAUDE.md theme bullet, MONAD_INTEGRATION.md removed, rules untouched)

Follow-up answers: the design is direction **C · Torchlight** from the canvas https://claude.ai/artifact/BqzwkXjLNihxQtpXrK8dvX (boards "C · Torchlight", "C · Five players", "C · The fight", "C · Lobby"); no tests; remove **everything Monad-specific** (theme, Monad Games ID / Privy sign-in, wallets, blockchain submission, backend included); debug UI only in dev builds.

The whole redesign is split in five parts: **1 foundation** (this plan) → 2 field (torchlight, tokens, move marks) → 3 HUD (turn-order rail, pack, chronicle, action rings) → 4 fight and dialogs → 5 lobby and rules page. This part must not restyle what parts 2-5 rebuild (BattleReportModal, ActionLog, the field tiles/tokens, the lobby layout, RulesView content); it gives them the tokens, fonts, buttons and icons they will use.

## Requirements Reconciliation
Authority: user answers > canvas boards (visual reference) > existing code. E2E contract (`fe/e2e/tests/two-player-game.spec.ts`, `fe/e2e/utils/game-helpers.ts`) must keep passing unchanged.

| Decision | Source | Verification |
|---|---|---|
| **One theme.** Delete `fe/src/theme/monad.js`, `fe/src/styles/themes/monad.css`, the registry in `fe/src/theme/themes.js` (fold into `index.js` exporting the base theme directly), `VITE_THEME` handling in `fe/vite.config.js`, `fe/vite.config.prod.js`, `fe/vite-plugins/themeHtml.js` (keep the plugin only if it still stamps title/manifest — then hard-code base), `compose.prod.yml` build arg, `html[data-theme]` + `applyTheme()` (`main.js:141`). `theme.monadGamesId`, `.monad-button`, `.monad-card`, `ThemeLogo` monad branch go. `fe/public/themes/monad/` (if present) deleted; base assets stay in `fe/public/themes/base/`. | user "удалить Monad, всё Monad-специфичное" | `grep -rni "monad" fe/src fe/vite* fe/vite-plugins` → only the project name in strings like the repo URL, if any; build passes |
| **No sign-in, no wallets (FE).** Delete `fe/src/services/privy.js`, `privyAuth.js`, `monadAuth.js`, `fe/src/components/PrivyAuth.vue`; HomeView loses the sign-in block, `privyUserId`/`walletAddress` reads and `localStorage.privyUser/walletAddress/monadUsername`; `api.joinGame` drops `externalId`/`walletAddress` params (keep `username`: the lobby sends none today unless set — keep the param for the leaderboard); LeaderboardView drops the wallet column; `fe/package.json` drops `@privy-io/js-sdk-core` and `viem` (`npm uninstall` in the frontend container, lockfile updated); `fe/.env.example` drops `VITE_PRIVY_APP_ID`. | user | build passes; lobby creates a game; leaderboard page renders |
| **No blockchain, no wallets (backend).** Delete `src/src/Game/Blockchain/**` and its `di.php` import, `src/src/Api/Auth/MonadLogin/**`, `src/src/Api/Auth/CheckWallet/**`, empty `src/src/Game/Auth/`; `composer remove web3p/ethereum-tx web3p/web3.php` in the php container; `.env`/`.env.local`/`.env.test` lose `MONAD_*` (only keys, never print values). Drop `walletAddress` from `AddPlayer`, `PlayerAdded`, `Player` (column + getter + status array), `Api/Game/AddPlayer/Request.php` + `Action.php`, `Leaderboard` (column, constructor arg, getter), `UpdateLeaderboardOnGameEnd` (lookup: username + externalId, then username only — as today's fallback), `Api/Leaderboard/Get/Action.php` output. `externalId` stays (generic identity). Migration through `doctrine:migrations:diff`: drop `player.player.wallet_address`, `game.leaderboard.wallet_address` and the `unique_player_wallet` index (this also ends the known schema drift). | user; memory "migrations via diff" | PHPUnit full suite; `doctrine:schema:validate` clean; one game finished through the API updates the leaderboard row by username |
| **Fonts.** Self-hosted, no CDN: `@fontsource/pirata-one` (display) and `@fontsource/spectral` 400/600/400-italic (body) imported in `main.js`. Tokens `--font-display: 'Pirata One', 'Spectral', Georgia, serif` and `--font-body: 'Spectral', Georgia, serif`. Remove the six conflicting `font-family` declarations (`assets/main.css:9,49`, `App.vue:18`, `LeaderboardView.vue:324`, `HomeView.vue:1339` keeps monospace only if it is a code/ID field). Headings h1-h3 → display font; body → body font. | canvas boards | screenshot of lobby and game in host Chrome |
| **Palette tokens** (replace values in `fe/src/styles/design-tokens.css`, keep the existing variable names so the 767 `var(--…)` uses follow): `--bg-primary #070504`, `--bg-secondary #120b07`, `--bg-tertiary #1c120c`, `--bg-panel rgba(20,12,8,0.92)` (new), `--text-primary #eadcc0`, `--text-heading #f4e3c3` (new), `--text-secondary #c9b493`, `--text-muted #8f7a5c`, `--text-accent #ffb35c`, `--color-primary #ff8c28` (ember), `--color-primary-hover #ffa24d`, `--color-secondary #c9762e`, `--color-danger #d9452a` (blood), `--color-info #9cc3ea` (you / ally blue), `--iron #5b5653`, `--iron-dark #3b3735`, `--iron-edge #121010` (new), `--ember-rgb 255,140,40`; `--accent-rgb` → ember. Radii shrink to 0-4px (iron and parchment, not rounded web cards); `--shadow-glow` → ember glow. Tokens move to a global import: `main.js` imports `@/styles/index.css` (today only GameView's unscoped style pulls it in), GameView's `@import` removed. | canvas | grep shows the new values; lobby/game screenshots |
| **Buttons.** One system in `fe/src/styles/components/buttons.css`: `.btn` + `.btn--ember` (primary: ember hairline, warm fill, display font), `.btn--ghost` (bone hairline, transparent), `.btn--iron` (round 64px iron ring for HUD actions, used from part 3), sizes `--sm/--md/--lg`, `:focus-visible` ember outline, `:disabled` 40% opacity. The legacy `.btn-primary/secondary/success/danger` in `assets/main.css:113-172` become aliases of the new variants (BattleReportModal/InventoryFullDialog keep working until part 4). Existing semantic classes the e2e uses (`.end-turn-btn`, `.finalize-battle-btn`, `.accept-defeat-btn`, `.pick-up-btn`, `.leave-item-btn`, …) are kept and only re-skinned. HomeView `.primary-button/.secondary-button` restyled to ember/ghost (layout untouched; part 5). | canvas; e2e | e2e by the user; screenshots |
| **Icons instead of emoji.** New `fe/src/components/ui/GameIcon.vue` (`name`, `size`, `title`; inline stroke SVG, `currentColor`, `aria-hidden` unless `title`): heart, treasure, dice, crown, skull, pin (current turn), sword, explore, spells, journal, hourglass, sound-on, sound-off, center, zoom-in, zoom-out, swap, key, close, check, warning, trophy, home, eye. Part 1 replaces emoji used as chrome in: `GameView.vue` (badges 💀👑⚔️📍❤️💎🎲, mode headings 🌀🌑💀🔮⚡🏃👂🪓, game-over 🏆🥇🥈👑💎🏠🔄🚫, toasts, fountain markers 🌿), `HomeView.vue` buttons and switch labels, `MusicToggle.vue`, `ZoomControls.vue`, `PlayerSwitchNotification.vue`, `GhostTile.vue`, `GameTile.vue` (🔍 only), `HealingNotification.vue`, `MissingKeyDialog`, `StealthChoiceDialog`, `CurseChoicePanel`, `DuelOpponentPanel`, `DuelResultPanel`, `LordKarakRevealModal`, `FateweaverChoiceDialog`, `InventoryFullDialog`, `ItemPickupDialog`. Kept for later parts: `BattleReportModal` (4), `ActionLog` and `useAiTurnPlayback` (3), field monster/item emoji fallbacks in `ThemedImage`/`GameTile`/`itemUtils`/`monsterUtils` (2), `RulesView` (5). Button texts the e2e matches stay word-for-word (`End Turn`, `Pick Up`, `Skip`, `Fight`, `Leave`, …) — only the emoji prefix goes. | user "сайт, а не игра"; e2e | `grep -P '[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]'` over the listed files → none; e2e |
| **Debug UI only in dev.** `GameView.vue:851-865` "Execute AI Turn Now (Debug)" and `:834-849` "AI Stuck" → `v-if="isDev && …"` with `const isDev = import.meta.env.DEV`; restyled as a small dim corner plaque. They keep their `.end-turn-btn` class: the e2e runs against the dev server, where they stay visible during AI turns, and today's `.first()` resolution must not change. HomeView `Build: …` → dev only. The AI auto-forcing timer stays in all builds (gameplay safety). | user | prod build (`vite build --mode production`) has no "Debug" string in `dist` (`grep -r "Execute AI Turn" dist`) |
| **"Game Setup" bug.** `GameView.vue:362-375`: `v-else` pairs with the Regent span → "Game Setup" shows all game long. Restructure: `<h3 v-if="turn">Turn: N</h3><h3 v-else>Game Setup</h3>` then the Regent chip. Keep it the **first `<h3>`** in the DOM with literal `Turn: ` text (e2e `document.querySelector('h3')`). | e2e map | screenshot; e2e |
| **Global surfaces.** `App.vue` global style → tokens (body `--bg-primary`, `--font-body`, `--text-primary`); `assets/main.css` reset keeps, typography moves to tokens; scrollbars dark; selection ember. Panels already using `--bg-*` follow automatically. | canvas | screenshots |
| **Out of scope** (parts 2-5): field lighting, tile tokens, turn-order rail, chronicle, battle screen, lobby layout, rules page; English copy stays English. | plan split | — |

## Commit Plan
- **Commit 1** (tasks 1-2): "chore: remove the Monad theme, sign-in, wallets and blockchain submission"
- **Commit 2** (tasks 3-5): "feat(fe): Torchlight foundation — fonts, palette tokens, buttons, icons"
- **Commit 3** (tasks 6-7): "feat(fe): emoji-free chrome, dev-only debug UI, Game Setup heading fix"
- **Commit 4** (task 8): "docs: one theme, no Monad"

## Tasks

### Phase 1: Remove Monad
- [x] Task 1: Backend — blockchain, Monad login, wallets
  - Delete `src/src/Game/Blockchain/**`, `src/src/Api/Auth/**` (MonadLogin, CheckWallet), empty `src/src/Game/Auth/`; remove the Blockchain `di.php` from wherever it is imported (`grep -rn "Blockchain" src/config src/src/Game/di.php`), and any console command registration.
  - `composer remove web3p/ethereum-tx web3p/web3.php` in `php_monadungeon`; `MONAD_*` keys out of `src/.env*` (edit keys without echoing values).
  - Drop `walletAddress` per the table (AddPlayer, PlayerAdded, Player incl. `getPlayerStatus` array, AddPlayer API Request/Action, Leaderboard entity/handler/API). Fix the PHPUnit doubles/tests that pass `walletAddress:` (grep `walletAddress` in `src/tests`).
  - Migration via `doctrine:migrations:diff`, description "Remove wallets"; migrate; `doctrine:schema:validate` must now be clean (the `unique_player_wallet` drift disappears).
  - Run the full PHPUnit suite. Logging: none added; `UpdateLeaderboardOnGameEnd` keeps its debug lines minus the wallet field.
- [x] Task 2: Frontend — theme registry, sign-in, wallet (depends on 1 for the API shape)
  - Delete monad.js, monad.css (and its `@import` in `styles/index.css`), privy.js, privyAuth.js, monadAuth.js, PrivyAuth.vue, `fe/public/themes/monad/` if present; fold `themes.js` into `theme/index.js` (base only; keep the `theme`, `getMonster*`, `getHero` exports so callers don't change); remove `applyTheme`/`data-theme`; vite configs and `themeHtml.js` without `VITE_THEME` (title/manifest from base); `compose.prod.yml` build arg removed; `npm uninstall @privy-io/js-sdk-core viem` in `frontend_monadungeon`; `.env.example` cleaned.
  - HomeView: sign-in block, Monad button, privy/wallet localStorage reads removed; `joinGame` call without externalId/wallet. `api.js` `joinGame(gameId, playerId, username, isAi, hero)` — update every caller (`grep -rn "joinGame(" fe/src fe/e2e`). LeaderboardView without the wallet column. `playerUtils`/`gameUtils` wallet references removed.
  - `npm run build`; ESLint on touched files must not add errors.

### Phase 2: Foundation
- [x] Task 3: Fonts and palette tokens (depends on 2)
  - `npm i @fontsource/pirata-one @fontsource/spectral`; import in `main.js` (`@fontsource/pirata-one/400.css`, `@fontsource/spectral/400.css`, `600.css`, `400-italic.css`).
  - `design-tokens.css` values and new tokens per the table; `--font-display`, `--font-body`; `main.js` imports `@/styles/index.css`, GameView's `@import` removed; `App.vue` and `assets/main.css` use the tokens; conflicting `font-family` declarations removed; h1-h3 display font.
- [x] Task 4: Button system (depends on 3)
  - Rewrite `styles/components/buttons.css` per the table; legacy `.btn-*` in `assets/main.css` as aliases; keep every e2e class; HomeView `.primary-button/.secondary-button` restyled via the variants.
- [x] Task 5: `GameIcon` (independent of 4)
  - `fe/src/components/ui/GameIcon.vue` with the icon set from the table (24×24 viewBox, 1.8 stroke, round caps — the canvas action icons are the reference paths for explore/spells/journal/hourglass).

### Phase 3: Chrome
- [x] Task 6: Emoji out of the chrome (depends on 4, 5)
  - Files and replacements per the table; music toggle and zoom controls as small iron rings; HP as `GameIcon heart` rows; treasure/dice/turn pin as icons; game-over screen icons; dialogs' headings and buttons. Keep `data-testid`s and the e2e texts.
- [x] Task 7: Dev-only debug UI and the heading fix (depends on 4)
  - `isDev` gating for the two AI buttons and `Build:`; restyle as a dim plaque; fix `GameView.vue:362-375` keeping the first `<h3>` = "Turn: N".
  - Verify: `npm run build` then `grep -r "Execute AI Turn" fe/dist` → nothing; screenshots in host Chrome of lobby and game (the user's tab is not touched: open a new page).

### Phase 4: Docs
- [x] Task 8: Docs (depends on 1-7)
  - README: drop the themes section's Monad rows/commands, "Build-time themes" bullet becomes "Theme: Torchlight (fonts, palette) in `fe/src/styles/design-tokens.css`"; delete `MONAD_INTEGRATION.md` and links to it (`DEPLOYMENT.md` mentions); CLAUDE.md "Theme" bullet updated (no `VITE_THEME`, no monad.css). Project name "Monadungeon" and the live URL stay.
