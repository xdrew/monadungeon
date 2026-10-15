# Implementation Plan: Torchlight redesign part 5 — the lobby, the rules book, the leaderboard and 404

Branch: feature/torchlight-lobby (created from feature/torchlight-fight @ 5a5d42b; the chain is merged to main together)
Created: 2026-10-15

## Original Request
Torchlight redesign part 5: lobby and rules page (Делай)

## Settings
- Testing: no new tests (no FE unit runner; e2e does not touch these pages). Checks: `npm run i18n:check`, build + `NODE_ENV=production` build, ESLint on touched files adds no errors, host Chrome in en/ru/pl and at phone width.
- Logging: standard. No new logs beyond the existing ones (`[i18n]`, game creation logs in HomeView).
- Docs: yes (CLAUDE.md Theme bullet, README UI section, memory `torchlight-redesign.md`).

User answers (2026-10-15):
- Lobby: **as on the canvas board** `TorchlightLobby` (https://claude.ai/artifact/BqzwkXjLNihxQtpXrK8dvX, `project/TorchlightLobby.dc.html`).
- Rules: **a rules book with a table of contents** — one parchment-dark text column, a sticky contents list on the left (on phones a contents block at the top), Pirata One headings, `GameIcon`/images instead of emoji.
- Leaderboard and 404 restyled in Torchlight, the dead `NotFound.vue` removed.

## Requirements Reconciliation
Authority: user answers > canvas board > existing code. e2e does not visit `/`, `/rules`, `/leaderboard` (games are created through the API), so no e2e contract constrains these pages; the lobby `data-testid`s stay anyway.

| Decision | Source | Verification |
|---|---|---|
| **Lobby layout** (board): the existing `theme.lobbyBackground` (`/themes/base/lobby-bg.webp`) full-bleed with `object-fit: cover`, darkened by a radial vignette around the left-centre and a left-to-right darkening towards the right column, plus a faint ember glow. Left top: "Karak" in `--font-display` ~150px (clamp for smaller screens) with an ember glow, under it an italic tagline. Right column (440px, right 96px, top ~150px): caption "Prepare the descent" (small caps, `--text-accent`), the hero block (random: an iron-ring medallion with the dice icon + "A hero awaits" + one line; chosen: the roster and the selected hero card as today, restyled to the same block), the options as a list of checkbox rows with a hairline divider (Random heroes, Karak: Regent, Lord Karak indented under Regent and disabled/dimmed while Regent is off — today it is hidden; it becomes visible-but-disabled like the board), the big ember button "Descend against the AI" (68px, `--font-display` 34px), and two ghost links "How to play" / "Hall of fame" side by side. Language and music rings stay top right. | user; canvas | screenshots en/ru/pl, 1440×900 and 390×844 |
| **Lobby behaviour unchanged**: `createNewGame` flow, localStorage keys (`regentExpansion`, `lordKarakVariant`, `randomHeroes`, `selectedHero`, …), all `data-testid`s (`hero-picker`, `random-heroes-toggle(-input)`, `hero-card-random`, hero tile testids, `hero-card`, `regent-toggle(-input)`, `lord-karak-toggle(-input)`). Turning Regent off still clears Lord Karak. | existing code | manual: toggle each option, reload, start a game |
| **Texts** through `t()` in en/ru/pl: `lobby.prepare` "Prepare the descent", `lobby.heroAwaits` "A hero awaits", tagline replaces `lobby.description` text ("Into the dungeon under the mountain — for treasure, and the Dragon's hoard."), `lobby.playAi` becomes "Descend against the AI" (ru «Спуститься против ИИ», pl «Zejdź do lochów przeciw SI»), links keep `lobby.rules` / `lobby.leaderboard` keys (texts "How to play" / "Hall of fame"). ru/pl follow the glossary. | i18n rules | `npm run i18n:check` |
| **Rules book**: `RulesView.vue` keeps its sections and keys; each section gets an `id` (`overview`, `win`, `monsters`, `items`, `battle`, `mechanics`, `regent`, `turn`, `tips`) and a heading with a `GameIcon` (no emoji anywhere: overview stats, items, battle steps, mechanics icons → `GameIcon` names or item images); a sticky `<nav>` contents list with anchor links and the current section highlighted (IntersectionObserver), on ≤900px a contents block above the text; the back link to the lobby and the language/music rings at the top. Text column max ~720px, Spectral 17px/1.6 on the dark ground, headings Pirata One, monster cards → a list of rows (image, name, strength, reward), `data-testid`s of the Regent blocks kept. | user | screenshots en/ru/pl; anchors scroll; console clean |
| **Leaderboard**: same data and pagination; purple gradients out; a "Hall of fame" page: Pirata One title, iron back ring, table with hairline rows, rank numerals in `--font-display`, the top three in ember/bone, sort markers as `GameIcon`-style chevrons (CSS triangles allowed), pagination as small iron rings. | user | screenshot with data (or the empty state) |
| **404**: `NotFoundView.vue` restyled (dark ground, big "404" in `--font-display` ember, text, ember button); `NotFound.vue` deleted after a grep proves nothing imports it. `ThemeLogo` stays as is (renders nothing in the base theme). | cleanup | grep; screenshot |
| **Palette only from tokens**: no `#8f86a8`/`#9d95b8`/`#ece8ff`/`#b7afd0` lavender greys, no `#2196F3` spinner, no purple, no gold `#ffd700` gradients in the touched files. | Torchlight foundation | grep of touched files |

## Commit Plan
- **Commit 1** (tasks 1-2): "feat(fe): Torchlight lobby and rules book"
- **Commit 2** (tasks 3-4): "feat(fe): Torchlight leaderboard and 404; docs"

## Tasks

### Phase 1: Lobby and rules
- [x] Task 1: Lobby per the board
  - `fe/src/views/HomeView.vue`: template regrouped as in the Reconciliation table (title block left, right column), Lord Karak row always rendered and disabled while Regent is off, CSS rewritten with tokens (drop lavender greys, blue spinner); keep script behaviour. Locale keys in en/ru/pl.
  - Logging: none new.
- [x] Task 2: Rules book (independent of 1)
  - `fe/src/views/RulesView.vue`: section ids, contents nav with active section, emoji → `GameIcon`/images, book typography, phone layout; new keys (`rules.contents`, section short titles if the h2 texts are too long for the nav) in en/ru/pl.

### Phase 2: Leaderboard, 404, docs
- [ ] Task 3: Leaderboard and 404 (independent of 1-2)
  - `fe/src/views/LeaderboardView.vue` restyle; `fe/src/views/NotFoundView.vue` restyle; delete `fe/src/views/NotFound.vue`.
- [ ] Task 4: Verify and docs (depends on 1-3)
  - i18n check, builds, ESLint on touched files, Chrome pass (lobby en/ru/pl desktop and phone, start a game from the lobby, rules anchors, leaderboard, a 404 URL); CLAUDE.md Theme bullet, README, memory.
