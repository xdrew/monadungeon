# Implementation Plan: Thief Hero (Aderyn) — Backstab & Stealth

Branch: feature/thief-hero (created from feature/warrior-hero @ cb24e4c; heroes are merged to main together)
Created: 2026-10-14

## Original Request
В мейн пока рано, сначала сделаем всех героев. Давай приступим к следущему герою, но замерим сколько токенов в итоге это займет

Hero chosen: Aderyn, the Thief. Showcase: Player 2 becomes the Thief.

Rulebook text (a4-karak-rulebook.pdf):
> Backstab. The Thief can attack from the shadows. Thanks to that, she wins any combat that would normally result in a tie.
> Stealth. The Thief can move in the shadows. When entering a tile with a monster, she can decide if she wants to fight it or leave it and continue moving.

## Settings
- Testing: yes (PHPUnit + the single showcase spec, Player 2 turns only)
- Logging: standard (message-bus middleware logs the new events; no extra log statements)
- Docs: yes (rules.md Heroes section, README bullet)

## Requirements Reconciliation
Authority: rulebook text (above) > existing implementation.

| Decision / supported combination | Source | Verification |
|---|---|---|
| Backstab converts DRAW → WIN at every result evaluation: weapons-only first roll, Double-Attack reroll path, finalize with consumables | "wins any combat that would normally result in a tie" | `ThiefBackstabTest`: tie on first roll auto-finalizes as WIN; tie after consumables → WIN |
| Backstab does not change LOSE; non-thief ties stay DRAW | — | same test, non-thief player |
| Frontend treats equality as victory only when the backend says `tieIsWin` (no client-side hero logic) | keep rules in the domain | modal buttons / predicted result use `tieIsWin` |
| Stealth = the existing `ignoreMonster` move flag, now allowed only for a hero with `canSneakPastMonsters()`; anyone else gets `InvalidMovementException` | "she can decide if she wants to fight it or leave it" | `ThiefStealthTest` |
| Sneaking moves the hero onto the monster tile without a battle; the monster stays; the move costs 1 action like any move; she may keep moving | "leave it and continue moving" | showcase Turn 2: P2 stands on the skeleton king's room, HP unchanged, turn continues |
| The choice is asked on both entry paths: moving onto a discovered monster tile and the auto-move after placing a room with a monster | "When entering a tile with a monster" | FE dialog in `fieldUtils.handlePlayerMovement` and the tile-placement move |
| Nobody uses `ignoreMonster: true` today (grep: 0 hits in fe/src, src/src, AI) so gating it is safe | code | — |

## Commit Plan
- **Commit 1** (tasks 1-3): "feat(hero): Thief Backstab — ties are victories"
- **Commit 2** (tasks 4-6): "feat(hero): Thief Stealth — sneak past monsters"
- **Commit 3** (tasks 7-9): "feat(fe): thief in lobby/modal; test(e2e): thief as Player 2; docs"

## Tasks

### Phase 1: Backstab (backend)
- [x] Task 1: Hero catalog
  - `src/src/Game/Player/HeroType.php`: `case THIEF = 'thief'`; `label()` "Aderyn, the Thief"; new `winsTies(): bool` (THIEF) and `canSneakPastMonsters(): bool` (THIEF); `canRerollBattle`/`reincarnates` false for THIEF; `skills()` texts from the rulebook.
  - `fe/e2e/utils/test-game-setup.ts`: `HeroType = 'warrior' | 'thief'`.
  - Files: HeroType.php, test-game-setup.ts.
- [x] Task 2: Backstab in the Battle aggregate (depends on 1)
  - `src/src/Game/Battle/Battle.php`: add `private function resolveResult(Item $monster, MessageContext $ctx): BattleResult` = `$monster->attack($this->totalDamage)`, and when it is DRAW and `GetPlayer()->getHero()?->winsTies() === true` return WIN. Use it at all four `->attack($this->totalDamage)` sites (finalizeBattle ×2, evaluateInitialRoll, processBattleResult).
  - `BattleCompleted`: add `public bool $tieIsWin = false` (true when the player's hero wins ties); pass it from `evaluateInitialRoll` and `finalizeBattle`. `Field::onBattleCompleted` copies it into `lastBattleInfo['tieIsWin']`.
  - `processBattleResult` FIGHT_MONSTER `additionalData`: `'backstab' => true` when the raw result was DRAW and the final is WIN; keep `heroAbility` = `'backstab'` in that case (Double Attack keeps `'double_attack'`; if both, `'double_attack+backstab'`).
  - Files: Battle.php, BattleCompleted.php, Field.php.
- [x] Task 3: PHPUnit Backstab (depends on 2)
  - `src/tests/Game/Battle/ThiefBackstabTest.php` (harness of `WarriorDoubleAttackTest`): (a) thief, dice [2,3] vs giant rat (5), no weapons → `BattleCompleted.result = WIN`, `tieIsWin = true`, `needsConsumableConfirmation = false`, FIGHT_MONSTER action has `backstab = true`; (b) non-hero same dice → DRAW with `needsConsumableConfirmation = true`, `tieIsWin = false`; (c) thief, dice [1,2] → LOSE (unchanged); (d) warrior reroll path: dice [1,1,2,3] with thief? not applicable — instead thief `rerollDice` throws (only warrior rerolls).
  - Run `docker exec php_monadungeon php vendor/bin/phpunit --filter ThiefBackstab`.

### Phase 2: Stealth (backend)
- [x] Task 4: Gate `ignoreMonster` behind Stealth (depends on 1)
  - `src/src/Game/Movement/Movement.php::movePlayer`: after computing `$destinationHasMonster` (undefeated monster at destination) and loading `$player`: if `$command->ignoreMonster && <undefeated monster there>` and `$player->getHero()?->canSneakPastMonsters() !== true` → `throw new InvalidMovementException('Only the Thief can sneak past a monster (Stealth)')`. When sneaking, add to the MOVE `PerformTurnAction` additionalData: `'sneakedPastMonster' => true, 'heroAbility' => 'stealth', 'monster' => [name,type,hp]`.
  - Check `src/src/Game/Field/Field.php` L823/L876 (deprecated `movePlayer` handler): confirm it is not registered for `MovePlayer` (grep `#[Handler]` above it); if it is, apply the same guard.
  - Files: Movement.php (+ Field.php if needed).
- [x] Task 5: PHPUnit Stealth (depends on 4)
  - `src/tests/Game/Movement/ThiefStealthTest.php`: build `Movement` via its factory from `MovementAfterBattleTest` harness; field with an undefeated giant rat at the destination: (a) thief + `ignoreMonster: true` → `PlayerMoved` dispatched, no `StartBattle`, MOVE action recorded with `sneakedPastMonster`; (b) non-hero + `ignoreMonster: true` → `InvalidMovementException`; (c) thief without the flag → `StartBattle`.
- [x] Task 6: Frontend Stealth choice (depends on 4)
  - New `fe/src/components/game/StealthChoiceDialog.vue` (style of `ItemPickupDialog.vue`): monster image + name, "⚔️ Fight (Enter)" `data-testid="stealth-fight"`, "🥷 Sneak past (S)" `data-testid="stealth-sneak"`, root `data-testid="stealth-dialog"`.
  - `fe/src/utils/fieldUtils.js`: both `gameApi.movePlayer` calls (L379 `handlePlayerMovement`, L549 tile-placement move) get `ignoreMonster` from a new optional `confirmStealth(position)` callback in `params`: when the current player's `hero` has stealth (`gameData.players[].hero === 'thief'`; expose `heroSkills` keys or a `canSneak` flag from the API — simplest: backend adds `heroFlags: {winsTies, canSneakPastMonsters, canRerollBattle, reincarnates}` to the player payload in `Get/Action.php`/`Response.php`) and `gameData.field.items[position]` is an undefeated monster (`guardHP > 0 && !guardDefeated`), await the callback → `ignoreMonster: true` when the player chose to sneak.
  - `fe/src/views/GameView.vue`: state `stealthPrompt = ref(null)` ({position, monster, resolve}); render the dialog; pass `confirmStealth` into the fieldUtils params; after a sneak move call `loadGameData(false)` so the player token moves onto the monster tile.
  - `fe/src/components/ActionLog.vue`: `move` with `sneakedPastMonster` → "🥷 Sneaked past <monster>".
  - Files: StealthChoiceDialog.vue, fieldUtils.js, GameView.vue, ActionLog.vue, Get/Action.php, Get/Response.php.

### Phase 3: Frontend ties + lobby
- [x] Task 7: Equality is a win when `tieIsWin` (depends on 2)
  - `BattleReportModal.vue`: helper `beatsMonster(damage) = battleInfo.tieIsWin ? damage >= battleInfo.monster : damage > battleInfo.monster`; use it in the consumable button conditions (`totalCalculatedDamage > battleInfo.monster` / `=== battleInfo.monster` at ~L421-437), `potentialVictoryWithConsumables`, `potentialImprovementWithConsumables`, `dynamicResult` (equality → 'win' when tieIsWin), the Enter key handler (~L1122). Show a "🗡️ Backstab! A tie counts as a victory" note (`data-testid="backstab-note"`) when `battleInfo.tieIsWin && battleInfo.diceRollDamage + itemDamage === battleInfo.monster` and result is win.
  - `GameView.vue` `handleFinalizeBattle` predictedResult: equality → 'win' when `battleInfo.value.tieIsWin`.
  - `HomeView.vue`: add the Thief hero card (🗡️, two skill lines); `selectedHero` accepts 'thief'; `readStoredHero` whitelist both.
  - `GameView.vue` `HERO_SHORT_NAMES.thief = 'Thief'`.
  - Files: BattleReportModal.vue, GameView.vue, HomeView.vue.

### Phase 4: Showcase + docs
- [x] Task 8: Thief as Player 2 in `two-player-game.spec.ts` (depends on 6, 7)
  - `playerConfigs[player2Id] = { hero: 'thief', maxHp: 1 }` (1 HP: she no longer loses on Turn 2, so Turn 8's loss must still stun her for Turn 10).
  - Turn 2: after placing the skeleton king's room, the stealth dialog appears → `GameHelpers.chooseStealth(page, 'sneak')` → no battle; assert via API that P2's HP is still 1 and she stands on the room tile; End Turn manually (the move no longer ends the turn). Remove the `1, 1` dice pair of Turn 2 from `diceRolls`.
  - Turn 12: dice `3, 3` → `2, 3` (5 = giant rat 5 → tie → Backstab victory); assert `[data-testid="backstab-note"]` visible, then pickup the dagger as today.
  - `game-helpers.ts`: `chooseStealth(page, action)` waits for `[data-testid="stealth-dialog"]` and clicks the button.
  - All Player 1 turns, tile and item sequences stay unchanged. Geometry note: P2 now starts Turn 4 from the king's room (2,0) instead of the gate (1,0); the spec places tiles by "first available" so it should adapt — confirm on the run.
  - Files: two-player-game.spec.ts, game-helpers.ts.
- [x] Task 9: Docs + quality (depends on 8)
  - `rules.md`: "#### Aderyn, the Thief" under Heroes (Backstab, Stealth); README bullet mentions both heroes.
  - `task csfix`-equivalent in the container, rector dry-run, PHPUnit, ESLint on changed FE files (no new errors), e2e showcase run (`task fe:test:video`).

## Token accounting for this hero
- ai-factory instructions loaded for this hero: none re-read (plan/implement/verify contracts already in context from the warrior); subagents: 0.
- Showcase runs needed: 5 (stealth geometry, auto-answering the Stealth prompt, turn 6 dice).
- Session-level numbers: user records `/cost` before and after.
