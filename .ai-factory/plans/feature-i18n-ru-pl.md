# Implementation Plan: Russian and Polish localization (ru, pl; en stays the source)

Branch: feature/i18n-ru-pl (created from feature/torchlight-hud @ 128843e; the chain is merged to main together)
Created: 2026-10-15

## Original Request
Дальше делаем ru и pl локализацию

## Settings
- Testing: yes. PHPUnit for the backend skill keys, plus a node script `fe/scripts/check-i18n.mjs` (`npm run i18n:check`) that compares the ru/pl key sets and `{placeholders}` against en and fails on a gap. No FE unit runner. The e2e runs in English and is run by the user.
- Logging: standard. `console.debug('[i18n] …')` on locale detection and switch, `console.warn('[i18n] missing key', key, locale)` in dev only (vue-i18n `missingWarn` limited to dev).
- Docs: yes (README "Localization" section, CLAUDE.md bullet).

Follow-up answers (2026-10-15):
- Scope: **all screens now**, as they are today. The redesign parts 4–5 later move the keys into their new markup.
- Language choice: **auto + switch**. Browser language (`ru*` → ru, `pl*` → pl, else en). An EN/RU/PL switch sits in the lobby and in the HUD tools row, and the choice is saved in `localStorage.locale`.
- Terms: **from the published editions** where they can be established (Karak is Polish, by Albi, and has a Russian edition); otherwise our own translation, marked in the glossary.
- Cyrillic display font: **Ruslan Display**. Pirata One has latin + latin-ext only (Polish is covered); Cyrillic glyphs fall back to Ruslan Display through the font stack.

## Requirements Reconciliation
Authority: user answers > e2e contract > existing code.

| Decision | Source | Verification |
|---|---|---|
| **Library: `vue-i18n@9`** (Composition API, `legacy: false`, `globalInjection: true`). `fe/src/i18n/index.js` creates the instance, `fallbackLocale: 'en'`, custom `pluralRules` for ru and pl (one / few / many / other by CLDR), `missingWarn`/`fallbackWarn` only in `import.meta.env.DEV`. Messages: `fe/src/i18n/locales/{en,ru,pl}.js` (JS modules, one nested object per area: `common`, `lobby`, `hud`, `game`, `battle`, `dialogs`, `chronicle`, `items`, `monsters`, `heroes`, `skills`, `rules`, `errors`, `leaderboard`, `notFound`). | user "ru и pl" | `npm run build`; `npm run i18n:check` |
| **Locale choice.** `detectLocale()` reads `localStorage.locale` (`en`/`ru`/`pl`, try/catch), else `navigator.languages`: the first `ru*` → ru, `pl*` → pl, else en. `setLocale(l)` saves the choice, sets `document.documentElement.lang`, updates `document.title` (router `meta.titleKey` + theme app name), logs `[i18n] locale`. Playwright's default `en-US` gives en, so the e2e stays English; `fe/playwright.config.*` also gets `use.locale: 'en-US'` to make that explicit. | user "авто + переключатель"; e2e | e2e by the user; host Chrome with `localStorage.locale` = ru/pl |
| **Switch** `fe/src/components/ui/LanguageSwitch.vue`: a small iron ring showing the current code (`EN`/`RU`/`PL`); click opens a 3-item menu (`role="menu"`, Esc closes), `aria-label` = "Language". Placed next to `MusicToggle` in the lobby (`HomeView.vue`) and in the HUD tools row (`GameView.vue` `#tools` slot). | user | screenshot of lobby and HUD in each locale |
| **e2e texts stay English in en**: the en messages keep every string the e2e matches *exactly*: `Turn: {n}` (banner h3; no text-transform), `End Turn`, `Place Tile First`, `Pick Up`, `Pick Up Item`, `Leave It`, `You found an item!`, `Inventory Full`, `Replace Selected Item`, `Leave Item`, `Fight, win, and pick up reward`, `Fight, win, and leave reward`, the `Pick … up … end … turn` and `End Turn … Treasure collected` button texts, `Rotate`, `Skip`, `Take`, `Confirm`, `Cancel`, `Processing...`, `Finalizing battle...` (also as `data-loading-status`), `Dagger` (item name). The `[data-loading-status]` attribute keeps the **English** value in every locale (it is a hook, not text). | e2e specs | grep of en.js for each string; e2e by the user |
| **Names: theme keys, not parsed English.** `theme/base.js` keeps the keys and images; displayed names come from `t('monsters.<key>.name')`, `t('heroes.<key>.name')` (full "Horan, the Warrior"), `.short` ("Horan"), `.role` ("the Warrior"). `HomeView.vue:237` (`split(', the ')`) and `useTurnOrder.js` (`split(',')`) switch to these keys. `getMonsterName`/`getHero(...).name` callers go through a new `fe/src/i18n/names.js` (`monsterName(key, form)`, `heroName(key, part)`, `itemName(type, form)`). | agent recon; user "из изданий" | grep: no `.split(', the ')` / `.name.split(',')` left |
| **Grammatical forms.** Sentences are whole keys with params; monster and item names have forms for ru/pl: `nom` (default), `acc` (direct object: "pokonuje {monster}" / "побеждает {monster}"), `ins` (ru "с {monster}" / pl "z {monster}"); en uses the same string with "the" in the sentence. Chronicle/banner/battle sentences pick the form in code: `t('chronicle.defeats', { who, monster: monsterName(key, 'acc'), total, hp })`. | ru/pl grammar | ru/pl screenshots of the chronicle after a won and a lost fight |
| **Plurals** with vue-i18n pipes: en `one | other`, ru/pl `one | few | many | other` (moves, HP, treasures, dice "ones"); custom `pluralRules` in `i18n/index.js`. "HP" → ru «ОЗ», pl «PŻ» (one glossary entry, used everywhere). | ru/pl grammar | key check covers placeholders; screenshot of "1/2/5" cases in the battle report if reachable, else code review |
| **Backend skills get stable keys.** `HeroType::skills()` entries gain `'key' => 'double_attack'` etc. (snake_case of the English name, unique across heroes); `HeroSkills::skills()` dedupes by `key`; the GET response passes them through unchanged (`Get/Action.php:71`, `Get/Response.php:263`). The FE shows `t('skills.<key>.name')` / `.description`, falling back to the server text when a key is missing. `heroLabel` is ignored by the FE (names come from `heroes.<hero>.name`). HomeView's duplicate `HERO_SKILLS` array goes; the lobby reads the same `skills.*` keys per hero (a static `HERO_SKILL_KEYS` map in `fe/src/i18n/names.js`, mirroring `HeroType::skills()` keys). | agent recon | PHPUnit: every hero's skills have a non-empty, unique snake_case `key`; Lord Karak's merged list has unique keys; `npm run i18n:check` covers `skills.*` for all keys in the map |
| **Server error texts stay English.** The ~91 domain exception messages are not translated (out of scope; they are rare and technical). The FE translates its own prefixes (`errors.darkThornFailed`: "Dark Thorn failed: {reason}") and shows the server reason as the param; `apiErrorMessage`'s fallback becomes `t('errors.unknown')`. | scope | code review |
| **Glossary** `.ai-factory/references/karak-glossary.md`: en / ru / pl for heroes (name, short, role), monsters, items and spells, skills, rooms (Battle Arena, Cursed Room, healing fountain, teleport gate), Lord Karak, Curse of Karak, HP; each ru/pl entry marked `official` (with the source URL) or `ours`. Established with web search before any locale file is written; locale files take terms only from it. | user "из изданий" | glossary file has a source per `official` entry |
| **Fonts.** `@fontsource/ruslan-display` (cyrillic subset) imported in `main.js`; `--font-display: 'Pirata One', 'Ruslan Display', 'Spectral', Georgia, serif`. Spectral's cyrillic and latin-ext subsets are already in `@fontsource/spectral` (unicode-range, loaded on demand). | user "Ruslan Display" | ru screenshot: banner and hero names in Ruslan Display, no tofu; pl screenshot: ą ę ł ż in Pirata One |
| **Dates/numbers**: the one `toLocaleTimeString()` (GameView ~3489) gets the current locale. | — | code review |
| **Not translated**: console logs, data-testids, CSS classes, `[data-loading-status]` values, AI strategy strings compared in code (`'moving toward item'`), backend exception texts, the art tool. | scope | — |

## Commit Plan
- **Commit 1** (tasks 1-3): "feat(i18n): vue-i18n with en/ru/pl, locale switch, glossary and Cyrillic display font"
- **Commit 2** (tasks 4-6): "feat(i18n): lobby, HUD, field and chronicle in ru and pl; skill keys from the backend"
- **Commit 3** (tasks 7-9): "feat(i18n): battle, dialogs, rules, leaderboard in ru and pl; key check; docs"

## Tasks

### Phase 1: Foundation
- [x] Task 1: Glossary
  - Web search for the official Polish (Albi) and Russian edition names of Karak and Karak: Regent heroes, monsters, items, spells, skills, rooms. Write `.ai-factory/references/karak-glossary.md` (table en / ru / pl, forms nom/acc/ins for monsters and items, `official` + source URL or `ours`).
  - Logging: n/a.
- [x] Task 2: i18n core (depends on 1)
  - `npm install vue-i18n@9` (in the frontend container); `fe/src/i18n/index.js` (instance, ru/pl `pluralRules`, `detectLocale`, `setLocale`, `useLocale` composable), `fe/src/i18n/names.js` (`monsterName`, `heroName`, `itemName`, `skillText`, `HERO_SKILL_KEYS`), `fe/src/i18n/locales/{en,ru,pl}.js` with `common`, `monsters`, `heroes`, `items`, `skills` filled from the glossary. Register in `main.js`; router `meta.titleKey` + title update; `<html lang>` follows the locale.
  - `fe/scripts/check-i18n.mjs` + `npm run i18n:check`: every en key exists in ru and pl and vice versa, same `{placeholders}`, same plural arity class (en 2 forms, ru/pl 4); exits 1 with a list.
  - `@fontsource/ruslan-display` + `--font-display` stack.
  - Logging: `console.debug('[i18n] locale', locale, 'from', source)` on start; `[i18n] switch` on change.
- [x] Task 3: Language switch (depends on 2)
  - `fe/src/components/ui/LanguageSwitch.vue`; mount in `HomeView.vue` next to `MusicToggle` and in the HUD `#tools` slot. Switching re-renders without reload.

### Phase 2: Game screen
- [x] Task 4: Backend skill keys (independent of 1-3)
  - `src/src/Game/Player/HeroType.php` `skills()` entries get `key`; PHPDoc shape updated; `HeroSkills::skills()` dedupes by `key`. Psalm types consistent (do not run psalm).
  - Test in `src/tests/Game/Player/HeroTest.php` (and `HeroSkillsTest.php` for Lord Karak): keys non-empty, snake_case, unique; run `docker exec php_monadungeon php vendor/bin/phpunit --filter 'HeroTest|HeroSkillsTest'`.
- [x] Task 5: Lobby, HUD, field (depends on 2, 4)
  - `HomeView.vue` (texts, toggles, hero cards: names/skills via keys, drop `HERO_SKILLS` and the `split(', the ')`), `GameView.vue` template and script strings (action notices with params, `heroTooltip`, `playerLabel`, banner headlines, waiting text, decision plaque panels), `components/game/hud/*`, `useTurnOrder.js` (`heroName(key, 'short')`), `GameTile.vue`, `GhostTile.vue` (`Rotate` stays "Rotate" in en), `ZoomControls`, `MusicToggle`, `AiTurnPlaybackControls`, `ReplayControls`, `LoadingOverlay` (`Processing...` in en), `ErrorDisplay`, `utils/itemUtils.js` (`formatItemName` → `itemName`, tooltips), `utils/fieldUtils.js` / `inventoryUtils.js` / `gameUtils.js` user-visible loading/error strings.
  - Logging: none new beyond `[i18n]`.
- [x] Task 6: Chronicle and AI playback labels (depends on 2)
  - `utils/chronicle.js`: every sentence → `t('chronicle.<key>', params)` with monster/item forms; `withArticle`/`humanize` go (en articles live in the en sentences). `composables/useAiTurnPlayback.js` labels → keys. The chronicle recomputes on locale change (computed reads the locale).

### Phase 3: Battle, dialogs, pages, docs
- [x] Task 7: Battle and dialogs (depends on 2)
  - `BattleReportModal.vue` (all texts incl. plurals "A 1 counted / N ones counted", Berserk, Sacrifice, the e2e button texts kept verbatim in en), `ItemPickupDialog`, `InventoryFullDialog`, `MissingKeyDialog`, `StealthChoiceDialog`, `FateweaverChoiceDialog`, `LordKarakRevealModal`, `CurseChoicePanel`, `DuelOpponentPanel`, `DuelResultPanel`, `HealingNotification`, the finished banner and the leaderboard overlay in GameView.
- [x] Task 8: Pages (depends on 2)
  - `RulesView.vue` (prose as keys per section/paragraph; HTML emphasis via `<i18n-t>` slots, no `v-html`), `LeaderboardView.vue`, `NotFound*.vue`, router titles.
- [x] Task 9: Verify and docs (depends on 1-8)
  - `npm run i18n:check` passes; `npm run build` (and `NODE_ENV=production`); ESLint on touched files adds no errors; PHPUnit filter from task 4.
  - Host Chrome, new tab, `http://127.0.0.1:18080`: lobby and a game turn (banner, rail, rings, chronicle after a fight, battle report, item pickup) in en, ru, pl; `document.documentElement.lang`; no `[i18n] missing key` warnings in the console; en texts of the e2e table unchanged (grep).
  - README "Localization" (how to add a key, the forms convention, the glossary, the check script), CLAUDE.md bullet (vue-i18n, `fe/src/i18n/`, keep en texts the e2e matches, run `npm run i18n:check`).
