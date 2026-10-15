# Implementation Plan: Torchlight redesign part 4 — the fight and the dialogs

Branch: feature/torchlight-fight (created from feature/i18n-ru-pl @ e933664; the chain is merged to main together)
Created: 2026-10-15

## Original Request
Torchlight redesign part 4: fight and dialogs (Продолжаем редизайн)

## Settings
- Testing: no new tests (no FE unit runner; the e2e is run by the user). Checks: `npm run i18n:check`, build + `NODE_ENV=production` build, ESLint on touched files adds no errors, host Chrome.
- Logging: standard. Keep the existing `console.debug/warn` of the battle flow; no new logs except `console.debug('[fight] phase', phase)` on phase changes in BattleReportModal (dev only via `import.meta.env.DEV`).
- Docs: yes (CLAUDE.md Theme bullet: the fight scene and the plaque dialogs; README UI section if it describes the battle modal).

User answers (2026-10-15):
- Fight: **full-screen scene** like the canvas board `TorchlightBattle` (https://claude.ai/artifact/BqzwkXjLNihxQtpXrK8dvX, file `project/TorchlightBattle.dc.html`).
- Dialogs (item pickup, inventory full, missing key, stealth, fateweaver): **into the HUD decision plaque**, like the curse and duel panels already are. Lord Karak's reveal stays a separate cinematic.
- Animations: **keep all current effects** (entrance, dice roll, result slam, screen flash, sparkles, idle/victory/defeat), re-coloured to the Torchlight palette.

## Requirements Reconciliation
Authority: user answers > e2e contract > canvas board > existing code.

| Decision | Source | Verification |
|---|---|---|
| **The scene** (board `TorchlightBattle`): black ground with two radial glows (cold blue behind the hero, ember behind the monster), the field faintly visible behind (`backdrop` = dark gradient over the live field at ~12% — no blur). Top centre: caption "A fight in the dark" (small caps, `--text-accent`) and the title "{hero} against {monster}" in `--font-display` 44px. Hero left (portrait 260×390, blue drop-shadow, floor shadow ellipse, name, hearts row from `GameIcon heart`), monster right (mirrored `scaleX(-1)`, ember drop-shadow, name, a one-line note such as "guards a key" when it guards a reward). Centre: two dice (SVG, tilted ±10°), the hero total and the monster strength in `--font-display` 96px with "vs" between, a breakdown line under the hero total ("4 + 2, dagger 1, fireball 1"), and the skill verdict box (blue border) for notes like Backstab. Bottom: the choice plaque (760px, ember border) with consumable cards (checkbox + image + name + effect), the reward (image + name) and the buttons. | user; canvas | screenshots in host Chrome: win, loss, draw, consumables, duel |
| **Logic untouched.** The script of `BattleReportModal.vue` (props, emits, computed, `isRolling`/`showResults`/phases, consumable maths, duel, tireless, curse, inventory replacement) keeps its behaviour; the work is template + CSS + presentational helpers. Emoji helpers (`getUsedItemEmoji`, `getInventoryItemEmoji`, `displayItemEmoji`, emoji at ~730/932) are replaced with images (`getItemImage`) or `GameIcon`. | scope | e2e by the user; code review of the script diff (only presentational helpers change) |
| **e2e contract kept**: root `.battle-overlay` (visible/hidden ~20 checks), `.battle-footer`, `.close-btn` with text `×` (`button:has-text("×")`), `.dice-face` (one per die), `.btn-defeat`, `.btn-draw`, `.btn-reroll`, all `data-testid`s (`reroll-battle`, `first-roll`, `backstab-note`, `combat-training-note`, `sacrifice-note`, `affinity-note`, `farseeing-note`, `berserk-note`, `bear-attack-note`, `lightning-retreat-note`, `curse-note`, `unstoppable-note`, `sacrifice-hp`, `tireless-continue`, `finalize-battle`, `duel-continue`, `duel-rival`, `duel-rival-label`), button texts matched by regex (`fight.*win.*pick`, `fight.*win.*leave`, `Pick.*up.*end.*turn`, `Leave.*item.*end.*turn`, `Leave.*(end.*turn|reward)`, `End Turn.*Treasure collected`, `Fight`, `Leave`). The footer stays `v-show="!isRolling"` (e2e waits for it). | e2e specs | grep of the new template for each selector; e2e by the user |
| **Dialogs in the plaque.** `ItemPickupDialog`, `InventoryFullDialog`, `MissingKeyDialog`, `StealthChoiceDialog`, `FateweaverChoiceDialog` lose their fixed overlays and render inside `DecisionPlaque` (GameView `#plaque` slot) like `CurseChoicePanel`. Their root keeps the e2e class / testid (`.item-pickup-overlay`, `.inventory-full-overlay`, `[data-testid="stealth-dialog"]`, `[data-testid="fateweaver-dialog"]`) even though it is no longer an overlay (the name is a hook). Button texts "Pick Up", "Leave It", "Replace Selected Item", "Leave Item", Cancel stay. `hasDecision` (GameView ~1309) includes them. While the battle scene is open the plaque is hidden (as today for the curse panel). | user; e2e | screenshot: pickup, inventory full, missing key, stealth, fateweaver in the plaque; field stays clickable around it |
| **Lord Karak reveal** stays a modal (`role="dialog" aria-modal="true"` kept), restyled as a cinematic: black ground, the Lord Karak portrait large with ember glow, title in `--font-display`, the absorbed heroes' skills as a list with `GameIcon`. | user | screenshot |
| **Palette only from tokens** (`design-tokens.css`): no purple/green leftovers (`rgba(102,187,106,…)` in CurseChoicePanel:103 and DuelResultPanel:191 → `--color-success`-like ember/bone or `--color-info`), no `backdrop-filter: blur` glassmorphism on dialogs. Buttons use `.btn--ember/--ghost/--blood/--iron`; `.btn-reroll`, `.btn-defeat`, `.btn-draw` stay as extra classes on those (hooks) with no own colours. | Torchlight foundation | grep of touched files for `backdrop-filter`, `#66bb6a`/`102,187,106`, emoji |
| **Dead UI goes**: `HealingNotification.vue` (import commented out) and `PlayerSwitchNotification.vue` (mounted inside an HTML comment) are deleted with their references; the legacy blocks of `styles/components/modals.css` that nothing uses (`.battle-report-*`, `.damage-comparison`, `.dice`, `.monster-emoji`, `.inventory-full-dialog`, `.replacement-item`, `.replace-btn`, `.skip-btn`, `.player-switch-notification`) are removed after a grep proves no template uses them; generic `.modal-*` classes are kept if used (leaderboard etc.) and re-coloured with tokens. | cleanup | grep before deleting; build |
| **Texts.** No new literals: new strings get `t()` keys in en/ru/pl (`battle.caption` "A fight in the dark", `battle.title` "{hero} against {monster}", `battle.guards` "guards {item}", breakdown parts). ru/pl titles use the nominative with a dash to avoid case agreement after "против/przeciw": ru «{hero} — {monster}», pl «{hero} — {monster}»; the breakdown uses item names in nom. Existing battle keys are moved, not rewritten; en texts the e2e matches stay verbatim. | i18n rules (CLAUDE.md) | `npm run i18n:check`; ru and pl screenshots |
| **Mobile / compact** (`max-width: 768px`, `max-height: 500px`): fighters shrink to ~120px portraits side by side on top, numbers 56px, the choice plaque becomes full width at the bottom with scrolling content; buttons ≥44px high. | HUD part 3 convention | Chrome device emulation 390×844 and 844×390 |

## Commit Plan
- **Commit 1** (tasks 1-3): "feat(fe): the fight as a full-screen Torchlight scene"
- **Commit 2** (tasks 4-6): "feat(fe): dialogs in the decision plaque, Lord Karak cinematic, dead notifications and legacy modal CSS gone"
- **Commit 3** (task 7): "docs: Torchlight fight scene and plaque dialogs"

## Tasks

### Phase 1: The fight scene
- [ ] Task 1: Fight primitives
  - New `fe/src/components/game/fight/`: `DieFace.vue` (SVG die 0–6 pips in bone on `--text-primary`, prop `value`, `rolling`, `tilt`; root keeps class `dice-face`), `FighterFigure.vue` (portrait image, side `hero|monster` for glow colour and mirroring, name, hearts or a note slot, animation state class prop `entrance|idle|victory|defeat|draw` so the existing keyframes apply), `ConsumableCard.vue` (label + checkbox + item image + name + effect; `selected` styling per board).
  - Logging: none.
- [ ] Task 2: BattleReportModal as the scene (depends on 1)
  - Rewrite the template of `fe/src/components/game/BattleReportModal.vue` into the board layout: `.battle-overlay` root (full screen, `--z-modal`), `.screen-flash` kept, header (caption + title, `.close-btn` `×` top right as an iron ring), fighters (hero via `FighterFigure`; monster via `FighterFigure` with the theme image; duel rival hero mirrored with `data-testid="duel-rival"`/`duel-rival-label`), centre roll (`DieFace` ×2 with the rolling values, totals, breakdown line, the damage bar kept as a thin bar under the numbers), skill notes as verdict boxes with their testids, reward/consumables/used consumables/inventory replacement and `CurseChoicePanel` inside the bottom choice plaque, `.battle-footer` with all branches, classes, testids and texts kept (buttons get `.btn--ember` for the primary action, `.btn--ghost` for leave/secondary, `.btn--blood` for defeat; `.kbd-hint` stays).
  - Emoji → `GameIcon` / item images everywhere in the template and in the emoji helpers; delete helpers that become unused.
  - CSS: replace the ~1300 lines of scoped style with the scene styles; keep every animation (26 keyframes) re-coloured to tokens (victory = ember, defeat = `--color-danger`, draw = bone). No `backdrop-filter`.
  - `console.debug('[fight] phase', …)` in dev when `isRolling`/`showResults`/`battleFinalized` change.
- [ ] Task 3: Scene on phones and texts (depends on 2)
  - Compact layout for `max-width: 768px` and `max-height: 500px` (see Reconciliation); i18n keys for the new strings in en/ru/pl; `npm run i18n:check`.
  - Host Chrome: a won fight, a lost fight, a draw, a fight with fireball selection, a duel (Regent game), in en and ru; 390×844 and 844×390.

### Phase 2: Dialogs and cleanup
- [ ] Task 4: Dialogs into the decision plaque (independent of 1-3)
  - `ItemPickupDialog`, `InventoryFullDialog`, `MissingKeyDialog`, `StealthChoiceDialog`, `FateweaverChoiceDialog`: drop overlay/backdrop/card chrome, keep root classes/testids, lay out for the plaque width (h4 title, short text, item image row, buttons `.btn--block`); mount them in `GameView.vue` inside the `#plaque` `DecisionPlaque` (move from lines ~472-513); extend `hasDecision`. Make sure nothing else hides the plaque while one is open and the rings do not offer conflicting actions (End Turn stays the single `.end-turn-btn` rule).
  - Logging: none new.
- [ ] Task 5: Lord Karak cinematic and panels (independent of 1-3)
  - `LordKarakRevealModal.vue` restyle (Reconciliation row). `CurseChoicePanel`, `DuelOpponentPanel`, `DuelResultPanel`: off-palette greens → tokens; check they read well in the plaque and inside the battle scene.
- [ ] Task 6: Dead notifications and legacy modal CSS (depends on 4, 5)
  - Delete `HealingNotification.vue`, `PlayerSwitchNotification.vue` and their commented references in GameView; prune unused blocks of `fe/src/styles/components/modals.css` (grep each selector first), re-colour what stays with tokens.

### Phase 3: Docs
- [ ] Task 7: Verify and docs (depends on 1-6)
  - `npm run i18n:check`, build + `NODE_ENV=production` build, ESLint on touched files (no new errors; the 5 old errors of BattleReportModal should be gone or not increased), grep of the e2e selector list against the new templates.
  - Host Chrome pass over all dialogs in the plaque and the scene states (en, ru, pl spot check), console clean.
  - CLAUDE.md Theme bullet: the fight scene (`BattleReportModal` + `components/game/fight/`), dialogs live in the decision plaque; README if it mentions the battle modal. Update memory `torchlight-redesign.md`.
