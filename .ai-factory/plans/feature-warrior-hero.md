# Implementation Plan: Warrior Hero (Horan) — Double Attack & Reincarnation

Branch: feature/warrior-hero (created from origin/main @ 5dac93e)
Created: 2026-10-13

## Original Request
Давай сделаем умения персонажей через https://github.com/lee-to/ai-factory

Для начала хочу сделать воина:

Horan, the Warrior
Skills :
Double Attack. The Warrior is skilled at combat. In any combat, he can reroll the dice if the first roll is not to his liking. He does not lose a HP in the first loss. However, he must accept the second roll's result.
Reincarnation. If the Warrior loses his last HP, he does not fall unconscious like the other weaklings, instead he teleports to any tile with a HF, where all his HPs are replenished. However, his turn ends anyway.

Важно оставить 1 тест и новые механики органично вписать в тот бой. А не писать отдельный тест под каждую механику. Мы видео того теста используем как шоукейс игры.

## Settings
- Testing: yes (PHPUnit unit tests for domain handlers + the single existing Playwright showcase spec extended; e2e is run by the user, never by the agent)
- Logging: standard (no new log statements inside aggregates; observability comes from the message-bus logging middleware — every new command/event below is logged automatically by `GameLoggingMiddleware`)
- Docs: yes (rules.md "Heroes" section + README feature bullet)

## Requirements Reconciliation
Authority: user request (verbatim Karak rulebook text) > `a4-karak-rulebook.pdf` (combat examples p.3, "Hit points and healing") > `rules.md` (current implementation status). Curse of Karak (which disables hero skills) is out of scope — the game has no curse mechanic.

| Decision / supported combination | Source path and section | Verification evidence |
|----------------------------------|-------------------------|-----------------------|
| Reroll is offered only when the weapons-only first roll is DRAW or LOSE (a weapons-only WIN is auto-finalized today and no sane player rerolls a win) | rulebook "Fighting example 1/2": Charles rolls, dislikes it, rerolls; `Battle::start` WIN-branch auto-finalizes | PHPUnit `WarriorDoubleAttackTest`: first roll WIN → `canReroll=false`; LOSE → `canReroll=true` |
| First-roll loss costs no HP: the first roll is never processed (no `ReducePlayerHP`) until the player either accepts it (FinalizeBattle) or rerolls | request "He does not lose a HP in the first loss"; `Battle::start` non-WIN branch only emits `BattleCompleted(needsConsumableConfirmation)` | PHPUnit: after `RerollBattleDice` no `ReducePlayerHP` was dispatched for the first roll |
| Second roll is final: `rerollUsed=true` → `canReroll=false`, second `RerollBattleDice` throws | request "he must accept the second roll's result" | PHPUnit: second reroll throws `\DomainException` |
| Consumables (fireball) are chosen AFTER the (re)roll, exactly like today: reroll replaces only the dice; weapons-only result is re-evaluated; consumable selection UI follows | rulebook example 1: reroll 7, THEN uses magic bolt | PHPUnit: `BattleCompleted` after reroll carries `availableConsumables` when result is not WIN |
| Non-hero players (`hero = null`, incl. AI) keep 100% current behaviour | — | existing PHPUnit suite + showcase spec Player 2 path unchanged |
| Reincarnation triggers only when HP reaches 0 and `hero->reincarnates()`; otherwise `PlayerStunned` as today | request "If the Warrior loses his last HP…" | PHPUnit `HeroTest`: warrior → `ReincarnationRequired`, no `PlayerStunned`; non-hero → `PlayerStunned` |
| Reincarnation is a two-phase action: the turn does NOT end after the losing battle; the player picks any discovered healing fountain (HF); then position is reset, HP restored to full, `EndTurn` is dispatched | request "teleports to any tile with a HF… turn ends anyway" | PHPUnit `ReincarnationTest`; showcase spec Turn 17 |
| The starting tile (0,0) is always an HF, so at least one target always exists (no fallback branch needed) | `Field::create` adds (0,0) to `healingFountainPositions` (Field.php ~L208) | showcase spec picks (0,0) |
| Battle-return auto-heal must not fire for a reincarnating warrior (he is teleported away instead) | `Field::onPlayerMoved` heals on `isBattleReturn` when standing on an HF | PHPUnit: `ResetPlayerHP` is dispatched once, by `CompleteReincarnation`, not by `onPlayerMoved` |
| "All HPs replenished" = `Player::MAX_HP` (5). `$maxHp` is not a persisted column today; persisting it is out of scope | Player.php L91 (no `#[Column]`), `resetHP()` uses `self::MAX_HP` | — |
| Hero is chosen before the game starts: via `AddPlayer.hero` (lobby is one-click) and `PickHero` while status is `preparing`; AI players get no hero | HomeView `createNewGame` (join → ready → start in one call) | API test via showcase `playerConfigs[player1Id].hero` |

## Commit Plan
- **Commit 1** (after tasks 1-3): "feat(hero): add HeroType, hero selection and test-mode wiring"
- **Commit 2** (after tasks 4-6): "feat(battle): warrior Double Attack reroll"
- **Commit 3** (after tasks 7-9): "feat(player): warrior Reincarnation flow"
- **Commit 4** (after tasks 10-12): "feat(fe): hero selection, double attack and reincarnation UI"
- **Commit 5** (after tasks 13-14): "test(e2e): weave warrior skills into showcase; docs: heroes section"

## Tasks

### Phase 1: Hero identity (backend)

- [x] Task 1: Hero catalog + Player state + migration
  - Create `src/src/Game/Player/HeroType.php`: `enum HeroType: string { case WARRIOR = 'warrior'; }` with `label(): string` ("Horan, the Warrior"), `canRerollBattle(): bool`, `reincarnates(): bool`, `skills(): list<array{name:string, description:string}>` (text from the request, used by API/FE).
  - `src/src/Game/Player/Player.php`: add `#[Column(type: Types::STRING, nullable: true, enumType: HeroType::class)] private ?HeroType $hero = null;` and `#[Column(type: BooleanType::class)] private bool $awaitingReincarnation = false;`; getters `getHero()`, `isAwaitingReincarnation()`. Keep legacy `characterId` untouched.
  - New messages in `src/src/Game/Player/`: `PickHero` (gameId, playerId, hero: HeroType) → handler `Player::pickHero` (throws `\DomainException` if the player is already ready; game-status check is done in the API via `GetGame`), dispatches `HeroPicked` event.
  - `src/src/Game/GameLifecycle/AddPlayer.php` and `PlayerAdded.php`: add `public ?HeroType $hero = null`; `Game::addPlayer` passes it through; `Player::onPlayerAddedToGame` sets `$player->hero`.
  - `src/src/Game/Battle/Battle.php`: add `#[Column(type: Types::BOOLEAN)] private bool $rerollUsed = false;` and `#[Column(type: JsonType::class, nullable: true, columnDefinition: 'jsonb')] private ?array $firstDiceResults = null;` (used in Phase 2, mapped now so one migration covers everything).
  - Migration `src/migrations/2026/10/Version2026101XXXXXXX.php` (generate with `doctrine:migrations:diff` inside the php container, then review): `player.player` add `hero VARCHAR(255) DEFAULT NULL`, `awaiting_reincarnation BOOLEAN DEFAULT FALSE NOT NULL`; `battle.battle` add `reroll_used BOOLEAN DEFAULT FALSE NOT NULL`, `first_dice_results JSONB DEFAULT NULL`. Run `task doctrine-validate` equivalent (`doctrine:schema:validate`) in the container.
  - Logging: none added; `HeroPicked` / `PlayerAdded(hero)` are logged by the bus middleware.
  - Files: Player.php, HeroType.php, PickHero.php, HeroPicked.php, AddPlayer.php, PlayerAdded.php, Game.php, Battle.php, new migration.

- [x] Task 2: Expose hero over the API (depends on 1)
  - `src/src/Api/Game/AddPlayer/Request.php` + `Action.php`: optional `?string $hero` validated with `#[Assert\Choice(callback: [HeroType::class, 'values'])]` (add static `values()` to the enum); pass `HeroType::from()` into `AddPlayer`.
  - New `src/src/Api/Game/PickHero/{Action,Request,Response}.php`, route `POST /game/pick-hero` `{gameId, playerId, hero}`; Action loads `GetGame`, rejects when `!status->isPreparing()`, dispatches `PickHero`. Register like the other actions (check `src/src/Api/routing.php` / `di.php` — the existing actions are attribute-routed; mirror `Api/Game/UseSpell`).
  - `src/src/Api/Game/Get/Action.php` (player data block ~L57-68) and `Get/Response.php::formatGameLifecyclePlayers` (~L183-220): add `hero` (string|null), `heroLabel`, `heroSkills`, `maxHp` (`Player::MAX_HP`), `awaitingReincarnation` (bool). `Player::getPlayerStatus()` (~L647): add the same three fields.
  - Logging: API errors already go through `ApiLoggingMiddleware`.
  - Files: Api/Game/AddPlayer/*, Api/Game/PickHero/*, Api/Game/Get/Action.php, Api/Game/Get/Response.php, Player.php.

- [x] Task 3: Test-mode wiring for heroes (depends on 1)
  - `src/src/Game/Testing/PlayerTestConfig.php`: add `public ?string $hero = null`.
  - `src/src/Game/Testing/SetupTestGameHandler.php` (~L82 `AddPlayer`): pass `hero: $playerConfig->hero !== null ? HeroType::from($playerConfig->hero) : null`.
  - `src/src/Api/Testing/SetupTestGame/Request.php` docblock `playerConfigs` shape: add `hero?: string|null`; check the handler/DTO mapping code that builds `PlayerTestConfig` from the array and add the key.
  - `fe/e2e/utils/test-game-setup.ts`: `PlayerTestConfig.hero?: 'warrior'`; update the comment at L9-10. `createGameWithPlayers` keeps posting `characterType` — rename that payload key to `hero` (backend never read `characterType`).
  - Files: PlayerTestConfig.php, SetupTestGameHandler.php, Api/Testing/SetupTestGame/Request.php (+ its Action if it maps manually), fe/e2e/utils/test-game-setup.ts.
<!-- Commit checkpoint: tasks 1-3 -->

### Phase 2: Double Attack (backend)

- [x] Task 4: Reroll command on the Battle aggregate (depends on 1)
  - New `src/src/Game/Battle/RerollBattleDice.php` (battleId, gameId, playerId, turnId; `@implements Message<void>`) and event `BattleDiceRerolled.php` (battleId, gameId, playerId, firstDiceResults, newDiceResults).
  - `Battle.php`: extract the body after `calculateInitialBattle()` in `start()` (L104-157: WIN → `BattleCompleted` + `processBattleResult`; else `BattleCompleted(needsConsumableConfirmation: true, availableConsumables)`) into `private function evaluateInitialRoll(Item $monster, MessageContext $ctx): void`. Compute `$canReroll = $player->getHero()?->canRerollBattle() === true && !$this->rerollUsed` and pass it to the non-WIN `BattleCompleted` as `canReroll`.
  - New `#[Handler] public function rerollDice(RerollBattleDice $command, MessageContext $ctx): void` — guards (all `\DomainException` with clear messages): game not finished (`GetGame`), `!$this->battleCompleted`, `!$this->rerollUsed`, `GetCurrentTurn` equals `$command->turnId`, `GetCurrentPlayer` equals `$command->playerId`, player's hero can reroll. Then `$this->firstDiceResults = $this->diceResults; $this->rerollUsed = true; $this->rollDice($ctx); $this->calculateInitialBattle($ctx);` dispatch `BattleDiceRerolled`, then `evaluateInitialRoll($this->getMonster(), $ctx)` (now yields `canReroll=false`, `rerolled=true`, `firstDiceResults`).
  - `BattleCompleted.php`: add `public bool $canReroll = false, public bool $rerolled = false, public ?array $firstDiceResults = null` (keep existing positional order; append at the end).
  - `processBattleResult()` FIGHT_MONSTER `additionalData`: add `'rerolled' => $this->rerollUsed, 'firstDiceResults' => $this->firstDiceResults, 'heroAbility' => $this->rerollUsed ? 'double_attack' : null` (used by replay/ActionLog).
  - `src/src/Game/Field/Field.php::onBattleCompleted` (~L1109-1250): copy `canReroll`, `rerolled`, `firstDiceResults` into `$this->lastBattleInfo`. Verify the handler has no "already processed this battleId" guard that would ignore the second `BattleCompleted` for the same battle; if it has, key it on `(battleId, rerolled)`.
  - Test-mode note: a reroll consumes the next two seeded dice from `Field::$testDiceRolls` — document in the showcase spec comments.
  - Files: Battle.php, RerollBattleDice.php, BattleDiceRerolled.php, BattleCompleted.php, Field.php.

- [x] Task 5: Reroll API (depends on 4)
  - New `src/src/Api/Game/RerollBattle/{Action,Request,Response}.php`, route `POST /game/reroll-battle` `{battleId, gameId, playerId, turnId}`. Action dispatches `RerollBattleDice`, then loads `GetField(gameId)` and returns `battleInfo: $field->getLastBattleInfo()` exactly like `Api/Game/MovePlayer/Action.php` L37-44 (so the FE can replace the open modal's `battleInfo` in place). Errors → `Error` DTO like `FinalizeBattle/Action.php`.
  - Files: Api/Game/RerollBattle/*.

- [x] Task 6: PHPUnit — Double Attack (depends on 4)
  - New `src/tests/Game/Battle/WarriorDoubleAttackTest.php`, copy the harness of `tests/Game/Battle/BattleTest.php` (anonymous-class player mock + real `Field::create` with `setTestDiceRolls`, stub handlers for GetPlayer/GetField/GetCurrentTurn/GetCurrentPlayer/GetGame/PerformTurnAction/PlayerMoved/ReducePlayerHP). Mock player exposes `getHero(): ?HeroType`.
  - Cases: (a) warrior, dice [1,1] vs giant rat (5), no weapons → `BattleCompleted.result=LOSE, canReroll=true`, no `ReducePlayerHP`; (b) then `RerollBattleDice` with seeded [6,6] → `BattleDiceRerolled`, `BattleCompleted.result=WIN, rerolled=true, firstDiceResults=[1,1], canReroll=false`; (c) reroll twice → `\DomainException`; (d) non-hero player → `canReroll=false` and `RerollBattleDice` throws; (e) warrior, dice [6,6] first → WIN auto-finalized, `canReroll=false`.
  - Run: `docker exec -it php_monadungeon php vendor/bin/phpunit --filter WarriorDoubleAttack` (container must be up: `task run`).
  - Files: tests/Game/Battle/WarriorDoubleAttackTest.php.
<!-- Commit checkpoint: tasks 4-6 -->

### Phase 3: Reincarnation (backend)

- [x] Task 7: Reincarnation domain flow (depends on 1)
  - New messages in `src/src/Game/Player/`: `ReincarnationRequired` (event: gameId, playerId, turnId), `CompleteReincarnation` (command: gameId, playerId, position: FieldPlace), `PlayerReincarnated` (event: gameId, playerId, position, reincarnatedAt). In `src/src/Game/Field/`: `Reincarnate` (command: gameId, playerId, turnId, targetPosition: FieldPlace).
  - `Player::reduceHP` (L150-163): when HP hits 0 and `$this->hero?->reincarnates() === true` → set `awaitingReincarnation = true`, dispatch `ReincarnationRequired` instead of `PlayerStunned`. New handler `Player::completeReincarnation(CompleteReincarnation)`: require `awaitingReincarnation`, set `hp = self::MAX_HP`, clear the flag, dispatch `PlayerReincarnated`.
  - `Battle::processBattleResult` (L475-515) and `Battle::finalizeBattle` (the unconditional `EndTurn` at ~L327): after the LOSE `ReducePlayerHP`, re-fetch `GetPlayer`; if `isAwaitingReincarnation()` → still dispatch `ResetPlayerPosition`/`PlayerMoved(isBattleReturn)` (keeps the "pushed back" visual), set `battleCompleted = true`, but do NOT dispatch `EndTurn` in either place.
  - `Field::onPlayerMoved` (L257-290): skip the battle-return auto-heal when the player `isAwaitingReincarnation()` (the `GetPlayer` call is already there).
  - `Field`: handler `onReincarnationRequired(ReincarnationRequired)` → `$this->lastBattleInfo['awaitingReincarnation'] = true; $this->lastBattleInfo['healingFountainPositions'] = [...strings]` (so `FinalizeBattle` responses / `GET /game` carry it). New `#[Handler] reincarnate(Reincarnate $command, MessageContext $ctx)` modelled on `useSpell` (L1753+): validate current turn & player, `GetPlayer()->isAwaitingReincarnation()`, target ∈ `getHealingFountainPositions()`; then `ResetPlayerPosition`, `CompleteReincarnation`, `PerformTurnAction(TurnAction::USE_HERO_ABILITY, additionalData: ['ability' => 'reincarnation', 'position' => ...])` (verify `TurnAction::isAllowedAfter(FIGHT_MONSTER)` returns true for USE_HERO_ABILITY — it does per L100-125, but confirm `GameTurn::performAction` does not auto-end on it), then `EndTurn`.
  - `src/src/Game/Turn/GameTurn.php` end-turn handler (`EndTurn`): throw `\DomainException('Choose a healing fountain to reincarnate first')` when the player `isAwaitingReincarnation()` — prevents the FE "End Turn" button / AI from skipping the choice. Note: `Field::turnStarted` stun handling stays as-is as a safety net.
  - Files: Player.php, ReincarnationRequired.php, CompleteReincarnation.php, PlayerReincarnated.php, Field.php, Reincarnate.php, Battle.php, GameTurn.php.

- [x] Task 8: Reincarnation API (depends on 7)
  - New `src/src/Api/Game/Reincarnate/{Action,Request,Response}.php`, route `POST /game/reincarnate` `{gameId, playerId, turnId, targetPosition: "x,y"}` (parse with `FieldPlace::fromString`, same as `UseSpell/Request`). Response: `{success, position}`.
  - `src/src/Api/Game/FinalizeBattle/Action.php` + `Response.php`: after dispatch, `GetPlayer` → add `awaitingReincarnation: bool` and, when true, `healingFountainPositions` from `GetField` so the FE can enter reincarnation mode without an extra round-trip.
  - Files: Api/Game/Reincarnate/*, Api/Game/FinalizeBattle/Action.php, Api/Game/FinalizeBattle/Response.php.

- [x] Task 9: PHPUnit — Reincarnation (depends on 7)
  - Extend `src/tests/Game/Player/PlayerTest.php` (or new `HeroTest.php` next to it): `pickHero` sets hero + emits `HeroPicked`; warrior at 1 HP + `ReducePlayerHP` → `ReincarnationRequired`, `awaitingReincarnation=true`, no `PlayerStunned`; non-hero → `PlayerStunned`; `CompleteReincarnation` → hp = MAX_HP, flag cleared, `PlayerReincarnated`; `CompleteReincarnation` without pending → throws.
  - New `src/tests/Game/Field/ReincarnationTest.php` (harness like `tests/Game/Field/HealingFountainTest.php`): `Reincarnate` to (0,0) dispatches `ResetPlayerPosition` + `CompleteReincarnation` + `PerformTurnAction(USE_HERO_ABILITY)` + `EndTurn`; target without HF → throws; wrong player/turn → `NotYourTurnException` / `\InvalidArgumentException`.
  - Run: `docker exec -it php_monadungeon php vendor/bin/phpunit --filter 'Reincarnation|Hero'`, then the full `task all` once.
  - Files: tests/Game/Player/PlayerTest.php or HeroTest.php, tests/Game/Field/ReincarnationTest.php.
<!-- Commit checkpoint: tasks 7-9 -->

### Phase 4: Frontend

- [x] Task 10: API client, hero selection in lobby, hero badge in the player panel (depends on 2)
  - `fe/src/services/api.js`: `joinGame(..., isAi = false, hero = null)` sends `hero`; add `pickHero(gameId, playerId, hero)`, `rerollBattle({battleId, gameId, playerId, turnId})` (returns `battleInfo`), `reincarnate({gameId, playerId, turnId, targetPosition})`.
  - `fe/src/views/HomeView.vue`: a hero picker above the "Create game" button (L46): two cards — "No hero" and "⚔️ Horan, the Warrior" with the two skill lines; selection stored in `selectedHero` ref + `localStorage('selectedHero')`; `createNewGame()` passes it to the human `joinGame` call (L919); AI join (L924) stays `null`. Styles: scoped in HomeView like the rest of the lobby, reuse `design-tokens.css` variables.
  - `fe/src/views/GameView.vue` player header (`.player-inventory-header`, ~L340-360): hero badge (`⚔️ Warrior`) with a `title` tooltip listing `heroSkills`; replace the hardcoded `/5` at L357 with `player.maxHp ?? 5`. Add a small "💀 choosing fountain…" state when `player.awaitingReincarnation`.
  - Files: api.js, HomeView.vue, GameView.vue, (optional) fe/src/styles/components/inventory.css.

- [x] Task 11: Double Attack in the battle modal (depends on 5, 10)
  - `fe/src/components/game/BattleReportModal.vue`: when `battleInfo.canReroll && !battleFinalized && !isProcessing` render `🎲 Double Attack — reroll (R)` (class `reroll-btn`, `data-testid="reroll-battle"`) next to the consumable/retreat buttons (~L420-445); keyboard `r` in the existing key handler (~L1080). Emit `reroll-battle` with `{battleId}`; add it to `defineEmits` (L495). When `battleInfo.rerolled` show the first roll struck-through (`firstDiceResults`) under the dice and a "Double Attack used — second roll stands" note. Re-run the dice rolling animation when `battleInfo.diceResults` changes (extend the watch at ~L529-549).
  - `fe/src/views/GameView.vue`: `handleRerollBattle` → `gameApi.rerollBattle({...})` → `battleInfo.value = { ...battleInfo.value, ...response.battleInfo }`; keep `lastProcessedBattleId` untouched (same battleId). Wire `@reroll-battle` on the modal (~L498).
  - Files: BattleReportModal.vue, GameView.vue, fe/src/styles/components/modals.css (button style).

- [x] Task 12: Reincarnation mode on the board (depends on 8, 10)
  - `fe/src/views/GameView.vue`: after `handleFinalizeBattle` / `handleFinalizeBattleAndPickUp` resolve, if `response.awaitingReincarnation` (or `gameData.players[me].awaitingReincarnation` after reload) → `isReincarnationMode = true`, `reincarnationTargets = healingFountainPositions`. Reuse the teleport-mode mechanics (`isTeleportMode` L895, target highlighting ~L94-132, `.teleport-mode` class) to highlight HF tiles and show a banner "💀 Reincarnation: choose a healing fountain" (`data-testid="reincarnation-banner"`). Clicking a highlighted tile → `gameApi.reincarnate(...)` → `loadGameData` → exit mode → `checkAndHandleVirtualPlayerTurn()`. Hide the End Turn button while in this mode.
  - `fe/src/components/ActionLog.vue`: render FIGHT_MONSTER with `rerolled` as "… used Double Attack (first roll X+Y)" and USE_HERO_ABILITY/reincarnation as "… reincarnated at the healing fountain (x,y)".
  - Files: GameView.vue, ActionLog.vue, fe/src/styles/components/gameView.css.
<!-- Commit checkpoint: tasks 10-12 -->

### Phase 5: Showcase e2e + docs

- [x] Task 13: Weave the warrior into the single showcase spec (depends on 3, 11, 12) — the agent edits the spec, the user runs `task fe:test:video`
  - `fe/e2e/utils/game-helpers.ts`: add `rerollBattle(page)` (clicks `[data-testid="reroll-battle"]`, waits for the dice animation), `chooseReincarnationFountain(page, x, y)` (waits for `[data-testid="reincarnation-banner"]`, clicks the highlighted tile `[data-position="x,y"]`, waits for the banner to disappear), and `moveWithItemDialog(page, 'x,y')` extracted from the inline `clickMoveAndHandleDialog` in Turn 19.
  - `fe/e2e/tests/two-player-game.spec.ts` — keep every Player 2 turn and all tile/item sequences unchanged; `playerConfigs[player1Id] = { hero: 'warrior', maxHp: 2 }`:
    - Turn 3 (rat 5): dice become `1, 1` (first roll 2 → LOSE, reroll button visible) then `4, 3` → click Double Attack → 7 > 5 WIN → pickup dagger as today. Assert the modal shows the struck-through first roll.
    - Turn 15 (dragon): unchanged `3, 3` → LOSE; assert the Double Attack button is visible but click "Accept defeat" (HP 2 → 1). Shows the hero can decline the reroll.
    - Turn 17 (dragon again): dice `2, 2` (first roll, 9 < 15) → Double Attack → new dice `1, 2` (3 + 5 = 8 < 15, still LOSE) → Accept defeat → HP 0 → reincarnation banner → `chooseReincarnationFountain(page, 0, 0)` → assert via API: P1 position `0,0`, hp = 5, `awaitingReincarnation=false`, and the turn changed to 18 without clicking End Turn.
    - Turn 19 ("4th-step item pickup"): P1 now starts at (0,0); replace the bounce with four moves down the corridor `0,-1 → 0,-2 → 0,-3 → 0,-4` using `moveWithItemDialog`; the 4th lands on the dagger tile (0,-4) → dialog → Leave It → auto-end (same assertion as today). Keep the manual End Turn fallback.
    - Turn 21: P1 is at (0,-4): first `moveWithItemDialog('0,-5')` (Leave It does not end the turn mid-way — `skipItemAndEndTurn` ends only on `pendingItemPickup`), then `movePlayer(dragonPos)` and the existing win/pickup/Game Over assertions.
    - Dice list becomes: `6,6 | 1,1 | 1,1 4,3 | 5,5 | 6,6 | 4,4 | 5,5 | 1,1 | 6,6 | 4,4 | 3,3 | 6,6 | 3,3 | 1,1 | 2,2 1,2 | 6,6` (comments updated per turn; rerolls consume the next pair). Update the header comment block in `CLAUDE.md` "Frontend E2E Testing" example only if it is copied from this spec (it is a doc example — keep it in sync).
    - Geometry assumption to confirm on the first video run: Player 1's corridor is the straight line (0,-1)…(0,-5) with the dragon at (1,-5) (the spec already hardcodes `0,-4`/`0,-5` and the `{x:1,y:-5}` fallback). If a run shows a different layout, only the Turn 19/21 coordinates need adjusting.
  - Files: fe/e2e/utils/game-helpers.ts, fe/e2e/tests/two-player-game.spec.ts, CLAUDE.md (example block).

- [x] Task 14: Docs + quality pass (depends on 13)
  - `rules.md`: new "## Heroes" section (hero chosen in the lobby; Horan the Warrior — Double Attack, Reincarnation — with the exact rules above and the ✅ markers convention; note that AI players have no hero).
  - `README.md`: one bullet under gameplay features ("Hero skills — Warrior: Double Attack & Reincarnation").
  - Run `task csfix` and `task rectorfix` (no psalm — the user runs psalm themselves), `docker exec -it php_monadungeon php vendor/bin/phpunit`, `task fe-lint`.
  - Files: rules.md, README.md.
<!-- Commit checkpoint: tasks 13-14 -->

## Notes for /aif-implement
- TaskCreate/TaskList tools are unavailable in this session; this file's checkboxes are the only progress source.
- Never run Playwright e2e (project rule); hand Task 13 to the user for the video run and iterate on their feedback.
- Do not run psalm (project rule).
- Upstream main moved during planning; all line numbers above were re-checked against `5dac93e`.
