# Implementation Plan: Swordsman Hero (Victorius) — Combat Training & Unstoppable

Branch: feature/swordsman-hero (created from feature/thief-hero @ 3380188; heroes are merged to main together)
Created: 2026-10-14

## Original Request
Victorius, the Swordsman hero: Combat Training (any die showing 1 may be rerolled as many times as needed) and Unstoppable (if at least one die shows 6 in combat, the turn continues; may fight the same monster again after a tie or loss). Same pattern as Warrior/Thief heroes (HeroType enum, battle flow, lobby + battle modal UI, woven into the single showcase e2e spec, rules.md section)

Follow-up answers: no e2e changes (showcase untouched, PHPUnit only); testing yes; logging standard; docs yes.

Rulebook text (a4-karak-rulebook.pdf):
> Combat Training. The Swordsman is an experienced fighter, and his strikes are always precise. If any die roll yields a 1, he can reroll it as many times as necessary to gain a better outcome.
> Unstoppable. If at least one of the dice yields a 6 during combat, the Swordsman can continue his turn afterwards. He can even fight the same monster if the combat was a tie, or if he lost.

## Settings
- Testing: yes (PHPUnit only; the showcase spec `fe/e2e/tests/two-player-game.spec.ts` is NOT changed — Player 1 stays Warrior, Player 2 stays Thief)
- Logging: standard (message-bus middleware logs the new event fields; no extra log statements)
- Docs: yes (rules.md Heroes section, README bullet)

## Requirements Reconciliation
Authority: rulebook text (above) > existing implementation (`Battle.php`, `Movement.php`, `GameTurn.php`).

| Decision / supported combination | Source | Verification |
|---|---|---|
| Combat Training is automatic: every die showing 1 is rerolled until it shows 2–6 (a 1 is the worst face, so "as many times as necessary" never favours keeping it). Applies to the initial roll of every battle. | "If any die roll yields a 1, he can reroll it as many times as necessary" | `SwordsmanCombatTrainingTest`: seeded dice `[1, 1, 4, 1, 5]` → final `[4, 5]`, `onesRerolled = 3` |
| Rerolled ones consume seeded test dice in order (`Field::getNextDiceRoll`), so test-mode sequences must list the rerolls. Non-Swordsman players never reroll ones. | code: `Field::getNextDiceRoll` pops one value per roll | same test, non-hero player keeps `[1, 1]` |
| Unstoppable = "the turn continues after this battle": hero is the Swordsman AND the final dice contain a 6 AND the player is not stunned by the result (HP after the battle > 0). | "If at least one of the dice yields a 6 during combat, the Swordsman can continue his turn afterwards" | `SwordsmanUnstoppableTest` (a)–(c) |
| On LOSE/DRAW with Unstoppable: HP loss and move-back happen as usual, but no `EndTurn` is dispatched and the post-battle movement lock (`Movement::$hasMovedAfterBattle`) is not set, so he may walk onto the monster tile again and start a new battle. | "He can even fight the same monster if the combat was a tie, or if he lost" | `SwordsmanUnstoppableTest` (a), `MovementUnstoppableTest` |
| On WIN with Unstoppable (weapons-only or via consumables): the turn is not ended by the backend; the frontend must not call end-turn after the modal (pick up / leave item → continue). | same rule, applies to every result | `SwordsmanUnstoppableTest` (d); FE buttons |
| A stunned Swordsman (lost his last HP) ends the turn as everyone else even with a 6; a hero without `continuesTurnOnSix()` never continues. | implicit: unconscious heroes skip | `SwordsmanUnstoppableTest` (c), (e) |
| After an Unstoppable battle the 4-action cap applies again: `GameTurn::hasBattleInTurn()` ignores FIGHT_MONSTER actions flagged `unstoppable`, so the turn auto-ends on the 4th move as in a battle-less turn. | prevents unlimited moves | `GameTurnTest` new case |
| Entering a monster tile is not counted as a MOVE action (existing behaviour for all players) — unchanged, noted only. | code: `Movement::movePlayer` records MOVE only when no battle starts | — |
| Backend owns the rules; the frontend only reads `onesRerolled` and `unstoppable` from `battleInfo` and changes labels/notes. | keep rules in the domain | modal + ActionLog |

## Commit Plan
- **Commit 1** (tasks 1-3): "feat(hero): Swordsman Combat Training — ones are rerolled"
- **Commit 2** (tasks 4-7): "feat(hero): Swordsman Unstoppable — a 6 keeps the turn going"
- **Commit 3** (tasks 8-9): "feat(fe): swordsman in lobby and battle modal; docs"

## Tasks

### Phase 1: Combat Training (backend)
- [x] Task 1: Hero catalog
  - `src/src/Game/Player/HeroType.php`: `case SWORDSMAN = 'swordsman'`; `label()` "Victorius, the Swordsman"; new `rerollsOnes(): bool` (SWORDSMAN) and `continuesTurnOnSix(): bool` (SWORDSMAN); existing flags stay false for SWORDSMAN; `flags()` gains `rerollsOnes`, `continuesTurnOnSix` (update the array shape docblock); `skills()` texts from the rulebook.
  - `fe/e2e/utils/test-game-setup.ts`: `HeroType = 'warrior' | 'thief' | 'swordsman'` (type only, no spec change).
  - `src/tests/Game/Player/HeroTest.php`: extend the enum test (values `['warrior', 'thief', 'swordsman']`, flags, skill names `['Combat Training', 'Unstoppable']`).
  - Files: HeroType.php, test-game-setup.ts, HeroTest.php.
- [x] Task 2: Rerolling ones in the Battle aggregate (depends on 1)
  - `src/src/Game/Battle/Battle.php`: new column `#[Column(type: Types::INTEGER, options: ['default' => 0])] private int $onesRerolled = 0;`. In `rollDice()`: reset `$onesRerolled = 0`; load the player (`GetPlayer`); when `$player->getHero()?->rerollsOnes() === true`, for each of the two dice `while ($value === 1) { ++$this->onesRerolled; $value = $field->getNextDiceRoll(1, 6); }` (rerolling a 1 again counts again). `totalDamage` is computed from the final dice as today.
  - `BattleCompleted`: add `public int $onesRerolled = 0`; pass `$this->onesRerolled` from `evaluateInitialRoll` (both events) and `finalizeBattle`. `Field::onBattleCompleted` copies it into `lastBattleInfo['onesRerolled']`.
  - `processBattleResult` FIGHT_MONSTER `additionalData`: `'onesRerolled' => $this->onesRerolled`; `heroAbility` list gains `'combat_training'` when `> 0` (keeps the `implode('+')` scheme: e.g. `combat_training+unstoppable`).
  - Migration: generate in the php container with `php bin/console doctrine:migrations:diff` (never hand-written), description "Swordsman: battle ones_rerolled counter"; run `doctrine:migrations:migrate` and `task doctrine-validate`.
  - Files: Battle.php, BattleCompleted.php, Field.php, new migration under `src/migrations/2026/10/`.
- [x] Task 3: PHPUnit Combat Training (depends on 2)
  - `src/tests/Game/Battle/SwordsmanCombatTrainingTest.php` (harness copied from `WarriorDoubleAttackTest`: `Field::create` + `setTestDiceRolls`, `createTester`/`createPlayer(HeroType)`, `lastBattleCompleted`, `findMessage`): (a) swordsman, seeded `[1, 1, 4, 1, 5]` vs giant rat (5) → `diceResults = [4, 5]`, `onesRerolled = 3`, `diceRollDamage = 9`, result WIN, FIGHT_MONSTER `onesRerolled = 3`, `heroAbility = 'combat_training'`; (b) swordsman, `[3, 4]` → `onesRerolled = 0`, `heroAbility = null`; (c) non-hero, `[1, 1]` vs dragon (15) → `diceResults = [1, 1]`, `onesRerolled = 0`; (d) warrior `[1, 2]` keeps its ones (Double Attack is a different skill).
  - Run `docker exec php_monadungeon php vendor/bin/phpunit --filter SwordsmanCombatTraining`.

### Phase 2: Unstoppable (backend)
- [ ] Task 4: Unstoppable in the Battle aggregate (depends on 1)
  - `Battle.php`: `private function turnContinuesAfterBattle(BattleResult $result, MessageContext $ctx): bool` = hero `continuesTurnOnSix()` && `in_array(6, $this->diceResults, true)` && HP after the battle > 0, where HP after = `$player->getHP() - ($result === LOSE ? 1 : 0)` when evaluated before `ReducePlayerHP` (events) and `$player->getHP()` when evaluated after it (`processBattleResult`).
  - `BattleCompleted`: add `public bool $unstoppable = false` (= the turn continues after this battle); set it in `evaluateInitialRoll` (both events) and `finalizeBattle`. `Field::onBattleCompleted` → `lastBattleInfo['unstoppable']`.
  - `processBattleResult`: compute `$unstoppable` after the HP reduction; FIGHT_MONSTER `additionalData['unstoppable'] = $unstoppable`, `heroAbility` gains `'unstoppable'`. Turn ending: for LOSE/DRAW dispatch `EndTurn` only when `!$awaitingReincarnation && !$unstoppable`; always set `battleCompleted = true`.
  - `finalizeBattle`: the trailing `EndTurn` is skipped when `$awaitingReincarnation || $unstoppable` (recompute after `processBattleResult` with the player's current HP).
  - Files: Battle.php, BattleCompleted.php, Field.php.
- [ ] Task 5: Movement and turn accounting (depends on 4)
  - `src/src/Game/Movement/Movement.php::onBattleCompleted`: set `hasMovedAfterBattle` only when `$event->monsterHP > 0 && !$event->unstoppable`.
  - `src/src/Game/Turn/GameTurn.php::hasBattleInTurn()`: ignore FIGHT_MONSTER entries whose `additionalData['unstoppable'] === true`, so the 4-action auto-end works again after an Unstoppable fight (docblock: "a battle that locked the rest of the turn").
  - Check `src/src/Game/Field/Field.php` deprecated battle/move handlers (grep `hasMovedAfterBattle|CannotMoveAfterBattle`) — apply the same `unstoppable` guard if any is still registered.
  - Files: Movement.php, GameTurn.php (+ Field.php if needed).
- [ ] Task 6: PHPUnit Unstoppable — battle (depends on 4)
  - `src/tests/Game/Battle/SwordsmanUnstoppableTest.php` (same harness; the tester must record `EndTurn` dispatches and reduce the fake player's HP on `ReducePlayerHP` as `WarriorDoubleAttackTest` does): (a) swordsman HP 3, dice `[6, 2]` vs dragon (15), no consumables → LOSE, `hpReductions = 1`, `unstoppable = true`, no `EndTurn`, FIGHT_MONSTER `heroAbility = 'unstoppable'`; (b) dice `[2, 3]` vs dragon → LOSE, `unstoppable = false`, `EndTurn` dispatched; (c) HP 1, dice `[6, 2]` vs dragon → LOSE, stunned, `unstoppable = false`, `EndTurn` dispatched; (d) `[6, 2]` vs skeleton king (10) with a +1 fireball → finalize WIN → no `EndTurn`, `unstoppable = true`; (e) non-hero `[6, 6]` vs dragon → LOSE, `EndTurn` dispatched; (f) `[6, 4]` vs skeleton king (10) → DRAW, no `EndTurn`, player moved back (`ResetPlayerPosition` to `fromPosition`).
  - Run `--filter SwordsmanUnstoppable`.
- [ ] Task 7: PHPUnit Unstoppable — movement and turn (depends on 5)
  - `src/tests/Game/Movement/MovementUnstoppableTest.php` (harness of `ThiefStealthTest`): feed `Movement::onBattleCompleted` a `BattleCompleted` with `monsterHP = 5`: `unstoppable = true` → a following `movePlayer` onto the monster tile dispatches `StartBattle` (no `CannotMoveAfterBattleException`); `unstoppable = false` → `CannotMoveAfterBattleException`.
  - `src/tests/Game/Turn/GameTurnTest.php`: new case — FIGHT_MONSTER with `additionalData['unstoppable' => true]` then MOVE actions: the 4th MOVE ends the turn (`TurnEnded` dispatched); control case without the flag keeps today's behaviour (no auto-end). Keep `BattleTurnEndingTest` green.

### Phase 3: Frontend + docs
- [ ] Task 8: Battle modal, lobby, action log (depends on 4)
  - `fe/src/components/game/BattleReportModal.vue`: notes under the dice (next to the Double Attack / Backstab notes): `battleInfo.onesRerolled > 0` → "🎯 Combat Training! Rerolled a 1 ×N" (`data-testid="combat-training-note"`); `battleInfo.unstoppable` → "⚡ Unstoppable! A 6 was rolled — the turn goes on" (`data-testid="unstoppable-note"`). Computed `turnContinues = !!battleInfo.unstoppable`; when true the footer labels read "Continue turn" (fallback / keys-same / guard-chest buttons), "🎒 Pick up and continue", "Leave item and continue", "✅ Replace and continue"; emitted events stay the same.
  - `fe/src/views/GameView.vue`: capture `const unstoppable = battleInfo.value?.unstoppable === true` at the start of each battle-modal handler and skip the manual `gameApi.endTurn` when it is true: `handleFinalizeBattle` (WIN branch ~L4160), `handleFinalizeBattleAndPickUp`, the pick-item / replace-item handlers reached from the modal (~L3800-3925), and `skipItem` via `isInventoryFullAfterBattle` (pass `isAfterBattle: isInventoryFullAfterBattle.value && !lastBattleUnstoppable`). `closeBattleReportAndEndTurn` already only refreshes state — after the refresh the same player is still active and can move. `HERO_SHORT_NAMES.swordsman = 'Swordsman'`.
  - `fe/src/views/HomeView.vue`: Swordsman hero card (🤺, `data-testid="hero-swordsman"`, two skill lines); `readStoredHero` whitelist adds `'swordsman'`.
  - `fe/src/components/ActionLog.vue` fight entry: prefix "Combat Training! (rerolled N ones)" when `details.onesRerolled > 0`; suffix "⚡ Unstoppable — turn continues" when `details.unstoppable`.
  - Files: BattleReportModal.vue, GameView.vue, HomeView.vue, ActionLog.vue.
- [ ] Task 9: Docs + quality (depends on 8)
  - `rules.md`: "#### Victorius, the Swordsman" under Heroes (Combat Training, Unstoppable, incl. the stunned exception and the 4-action cap); README "Hero Skills" bullet mentions the Swordsman.
  - Quality: `task csfix`-equivalent and rector dry-run in the container, full PHPUnit (`docker exec php_monadungeon php vendor/bin/phpunit`), ESLint on changed FE files (no new errors). Psalm and e2e are run by the user.

## Token accounting for this hero
- Session started fresh after `/clear`; `/cost` baseline before planning: $0.52.
- `/cost` after planning (before implementation): $4.87 → planning cost $4.35.
- ai-factory instructions loaded for this hero: aif-plan SKILL.md (this run); subagents: 0 (codebase read directly, ~15 Bash reads).
- Showcase runs needed: 0 (no e2e changes).
- User records `/cost` after the hero is finished; the difference is the Swordsman's cost.
