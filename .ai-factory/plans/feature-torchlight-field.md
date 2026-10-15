# Implementation Plan: Torchlight redesign, part 2 — the field (torchlight, tokens, move marks)

Branch: feature/torchlight-field (created from feature/torchlight-foundation @ 7d0cda4; the chain is merged to main together)
Created: 2026-10-15

## Original Request
редизайн в стиле C, часть 2

## Settings
- Testing: no (no FE unit runner; verification = `npm run build`, ESLint on touched files, screenshots in the host Chrome in a new tab, the user's e2e run)
- Logging: standard (no per-frame logs; one `console.debug` per light/marker recomputation is too noisy — log only on game-state changes, as the existing `[field]`-style logs do)
- Docs: yes (README "Theme" section, CLAUDE.md theme bullet)

Follow-up answers: darkness **atmospheric** (outside the torches the dungeon is dimmed to ~70%, monsters and chests stay readable; the acting hero has the widest light and a warm halo); tile size **150px** as on the canvas; settings as in part 1.

Design reference: canvas https://claude.ai/artifact/BqzwkXjLNihxQtpXrK8dvX, boards "C · Torchlight" (`project/Torchlight.dc.html`) and "C · Five players" (`project/TorchlightFive.dc.html`). This part owns only the field (`.game-field` and everything inside `.tiles-container`). Not here: turn-order rail, pack, chronicle, action rings, sidebar layout (part 3); the battle screen and the on-field `ai-battle-overlay`, dialogs (part 4); lobby, rules (part 5).

## Requirements Reconciliation
Authority: user answers > canvas boards > existing code. The e2e contract (`fe/e2e/utils/game-helpers.ts`, `fe/e2e/tests/two-player-game.spec.ts`) must keep passing unchanged.

| Decision | Source | Verification |
|---|---|---|
| **Tile 150px, one source.** `TILE_SIZE = 150` exported from `fe/src/utils/fieldUtils.js` (next to `FIELD_PADDING`). `GameView.vue:1260` `ref(TILE_SIZE)`, `:1719` stops forcing 100. `GameTile.vue:562`, `GhostTile.vue:219`, `styles/components/tiles.css:20` and `AvailableMoveMarker.vue:114` stop hard-coding 100px: width/height come from the `tileSize` prop (inline style) or `--tile-size`; `design-tokens.css --tile-size: 150px`. Everything positioned by `tileSize` (fog, heal text, battle overlay, markers, centering in fieldUtils) follows. | user "150px" | screenshot; `grep -rn "100px" GameTile.vue GhostTile.vue AvailableMoveMarker.vue tiles.css` shows no tile-size leftovers; e2e (bounding-box compare marker ↔ `.tile[data-position]` still works because both scale) |
| **Torchlight darkness.** New `fe/src/components/game/TorchlightLayer.vue`, rendered inside `.tiles-container` after the tiles: one `aria-hidden`, `pointer-events: none` layer over the field bounds +1 tile, fill `rgba(7,5,4,0.7)`, `mask-image` = one `radial-gradient(circle at <hero centre>, transparent <inner>, #000 <outer>)` per hero, `mask-composite: intersect` (+ `-webkit-mask-composite: source-in`). Radii in tiles: acting hero inner 1.0 / outer 2.8, others inner 0.35 / outer 1.4. Second layer: warm halo `radial-gradient(rgba(255,140,40,0.2) → 0, 1.9 tiles)` at the acting hero, `mix-blend-mode: screen`. Lights = hero positions as the figures show them (same source as `GameTile`'s `allPlayerIds`, i.e. `gameData.field.playerPositions`, so AI playback moves the light with the figure); acting = `gameData.state.currentPlayerId`. No darkness when the game is finished or there are no positions yet. `@supports not (mask-composite: intersect)` → plain edge vignette. Opacity transition 300ms, none under `prefers-reduced-motion`. | user "атмосферно"; canvas mask | screenshots: own turn, AI turn (light follows the AI figure), finished game (no darkness); clicks on markers/tiles still work |
| **Z-order.** Tiles and tokens (≤ z10) < fog (z3/4, restyled) < darkness (z20) < move/placement marks, ghost tile, fountain markers (≥ z50, unchanged) so actionable marks are never dimmed. Hero standees stay under the darkness but sit in the light holes. | canvas (reach marks drawn above the dark) | screenshot with a placement mark outside the torchlight |
| **Fog restyle.** `.fog-cell` / `.fog-fringe` (`styles/layout/game-layout.css:100-161`) from blue-black `rgba(12,11,24,…)` to the warm `--bg-field` tone, shimmer removed; logic untouched. | palette | screenshot |
| **Tokens on tiles** (`GameTile.vue`). Monster/item image as a free token with `drop-shadow(0 4px 6px rgba(0,0,0,.9))`: 48% of the tile (72px at 150), boss 58% (88px). HP number: `.monster-hp` kept as the class, restyled — no pill, `--font-display` 24px, `#ffd9a8`, `text-shadow: 0 0 6px #ff5a1a, 0 2px 2px #000`, bottom-right of the token. Weapon `+N` (`.weapon-damage`) same face, bone colour, 18px. Pickable reward: ember ring pulse instead of the current pulse. `frost_fist` / `dark_thorn` show `/images/frost-fist.webp` / `/images/dark-thorn.webp` (GameTile's `weaponImage` uses `getItemImage` from `itemUtils`). Emoji fallbacks on the field become `GameIcon`: `ThemedImage.vue` gains an optional `fallbackIcon` prop (icon name; when set, a failed/missing image renders `<GameIcon>` instead of the emoji — other callers unchanged until part 4), GameTile passes `skull` for monsters; the last-resort `<span>{{ itemEmoji }}</span>` becomes `<GameIcon :name="itemIconName">` from a new `getItemIconName(item)` in `itemUtils.js` (key → key, weapons → sword, spells → spells, chest/treasure/unknown → treasure). Fountain / gate / arena / cursed-room markers keep their images; their glows go warm (no blue/white). | canvas tokens; user "no emoji" (part 1) | `grep -nP` for emoji in `GameTile.vue` → none (the 👹 default goes); e2e soft check `:has-text("🐉")` only logs, as today |
| **Hero standees** (`GameTile.vue .player-indicator`). Each hero a standee 40% × 60% of the tile (60×90 at 150), `object-fit: contain`, drop shadow, no glowing discs (`playerGlow` / `aiGlow` keyframes go). Placement on the tile by count: 1 → right of centre (canvas); 2 → left/right; 3–5 → spread in an arc, never fully overlapping. Ground mark under the feet: thin cold-blue ellipse (`--color-info`) for the human, ember ellipse + glow for the acting hero, none otherwise. Keep `data-testid="player-figure-<id>"`, `.swap-target`, the click handler, the portrait source and its fallback. | canvas standees; "you" = blue, acting = ember (canvas) | screenshot with two heroes on one tile |
| **Tiles without frames.** `.tile` borders, radius, hover lift and the inset `.current-player-position` effects go; tiles touch edge to edge like the canvas. Hover: faint bone inner hairline. `.tile-coordinates` + `.center-view-button` become a small iron ring (`btn btn--iron btn--sm`). Keep `.tile[data-position]`, `data-x/y`, all state classes. | canvas | screenshot |
| **Move marks** (`AvailableMoveMarker.vue`). Move: `2px solid rgba(255,140,40,.55)` frame + `inset 0 0 24px rgba(255,120,30,.35)`, no arrows (`.move-arrow` markup removed); hover brightens. Placement: dark slot `rgba(7,5,4,.55)` with a dashed ember hairline and `<GameIcon name="explore">` inside `.place-icon` (class kept — e2e clicks it). Disabled: iron hairline, 40% opacity. Unchanged: root classes `available-place move-marker` + `clickable` / `place-tile` / `disabled`, the `title` text `Available position: x,y …`, DOM order, `force:true`-clickable. | canvas reach marks; e2e | e2e by the user; screenshot |
| **Ghost tile** (`GhostTile.vue`). Ember frame and glow instead of the current accent; `.ghost-rotate-btn` as a small iron ring (class and `title`/`aria-label` kept). | canvas | screenshot while placing |
| **Fountain choice markers** (`GameView.vue` `.healing-fountain-marker`, teleport and reincarnation). Ember frame like move marks + the fountain heart, warm pulse; `data-testid="reincarnation-target-x,y"` kept. | canvas | screenshot in teleport mode if reachable, else code review |
| **Out of scope:** `ai-battle-overlay` (part 4), sidebar and HUD (part 3), `useAiTurnPlayback` text (part 3), dialogs (part 4). | plan split | — |

## Commit Plan
- **Commit 1** (tasks 1-3): "feat(fe): 150px tiles and torchlight darkness on the field"
- **Commit 2** (tasks 4-5): "feat(fe): free tokens and hero standees on the field"
- **Commit 3** (tasks 6-7): "feat(fe): ember move marks, ghost tile and fountain markers; docs"

## Tasks

### Phase 1: Scale and light
- [x] Task 1: Tile size 150px from one constant
  - `fe/src/utils/fieldUtils.js`: `export const TILE_SIZE = 150`; `GameView.vue` (`tileSize` ref at ~1260, the reset at ~1719) uses it; `design-tokens.css --tile-size: 150px`.
  - Remove hard-coded 100px tile sizes in `GameTile.vue` (~562), `GhostTile.vue` (~219), `AvailableMoveMarker.vue` (~114), `styles/components/tiles.css` (~20): size from the `tileSize` prop (inline `width/height`) or `var(--tile-size)`.
  - Check every `tileSize`-based position (fog cells `tileSize+8`, floating heal text, battle overlay, centering helpers) still lines up; `FIELD_PADDING` stays 100.
  - Logging: none new.
- [x] Task 2: `TorchlightLayer.vue` (depends on 1)
  - New `fe/src/components/game/TorchlightLayer.vue`; props `lights` (`[{ x, y, acting }]` in field coords), `minX`, `minY`, `cols`, `rows`, `tileSize`, `active`. Renders the darkness + halo layers per the table (inline `mask-image` string built in a computed). `@supports` fallback, reduced-motion.
  - `GameView.vue`: computed `torchLights` from `gameData.field.playerPositions` and `gameData.state.currentPlayerId`; `active` false when finished or no positions. Place `<TorchlightLayer>` in `.tiles-container` after `<GameTile>`s, z-index 20; confirm markers/ghost/fountain markers are ≥ z50.
  - Logging: `console.debug('[torchlight] lights', count, 'acting', id)` only when the set of lit positions changes (watch, not computed).
- [x] Task 3: Fog in the palette (independent of 2)
  - `styles/layout/game-layout.css` `.fog-cell`, `.fog-fringe*`: warm `--bg-field` tones, no shimmer keyframes; keep the classes and the dissolve class.

### Phase 2: Tokens and standees
- [ ] Task 4: Free tokens (depends on 1)
  - `GameTile.vue` tokens, HP and `+N` per the table; pickable ember ring; frost_fist/dark_thorn images via `getItemImage`; boss size.
  - `ThemedImage.vue` `fallbackIcon` prop; `itemUtils.js getItemIconName`; GameTile uses both; remove the `'👹'` default.
  - Fountain/gate/arena/cursed-room glows warm.
- [ ] Task 5: Hero standees and frameless tiles (depends on 1)
  - `.player-indicator` layout by count, standee size, ground marks (human blue, acting ember), drop the glow keyframes; keep testids, `.swap-target`, clicks.
  - Tiles edge to edge: no border/radius/hover lift; bone hover hairline; `.center-view-button` → `btn btn--iron btn--sm`.

### Phase 3: Marks and docs
- [ ] Task 6: Move marks, ghost tile, fountain markers (depends on 2)
  - `AvailableMoveMarker.vue` per the table (arrows out, `GameIcon explore` in `.place-icon`), keeping classes, `title` and order.
  - `GhostTile.vue` ember frame, iron-ring rotate button.
  - `GameView.vue` `.healing-fountain-marker` styles (teleport + reincarnation) as ember frames.
  - Verify: `npm run build`; ESLint on touched files adds no errors; host Chrome (new tab, `http://127.0.0.1:18080`): own turn with placement and move marks, a tile placed, the AI turn (light follows the AI), two heroes on one tile if reachable.
- [ ] Task 7: Docs (depends on 1-6)
  - README "Theme": the field — 150px tiles, `TorchlightLayer` (darkness, torches, acting hero), tokens and standees, ember move marks; CLAUDE.md theme bullet gets one clause about `TorchlightLayer` / `TILE_SIZE`.
