# Implementation Plan: AI player with a hero — random heroes, hero rendering, skill-aware decisions

Branch: feature/ai-heroes (created from feature/karak-regent-lord-karak @ cb9dab1; the chain is merged to main together)
Created: 2026-10-15

## Original Request
AI-игрок получает случайного героя при создании игры (только доступных: герои Regent — при включённом дополнении), рисуется как герой (портрет, фигурка, бейдж), и учитывает умения героя при планировании действий: пассивные в оценке боя, активные (Lightning Attack, Sprint, Listening, Tireless, Magic Swap, Fateweaver, Sacrifice, Double Attack, Stealth, Astral Walking, Dark Thorn, лимит лечения) — в выборе хода; Лорд Карак-AI тоже. Ветка от feature/karak-regent-lord-karak.

## Settings
- Testing: yes (PHPUnit only; `fe/e2e/tests/two-player-game.spec.ts` is NOT changed, the user runs e2e)
- Logging: standard (message-bus middleware logs commands/events; the AI reports every skill decision as an `ai_reasoning` action through `createAction` plus one `error_log('DEBUG AI: …')` line, matching `SmartVirtualPlayer` style; no new logger)
- Docs: yes (rules.md hero section, README bullet, RulesView)

Follow-up answers:
- By the rules heroes are dealt randomly. Lobby switch "Random heroes", **on by default**. On: the hero picker is hidden, the human and the AI both get random, distinct heroes. Off: the human picks, the AI gets a random hero from the remaining ones.
- Teach the AI spells: Dark Thorn on the leading rival, Portal of Healing (teleport) to a fountain, Frost Fist counted in battle like the fireball.
- An AI Warrior that loses his last HP chooses the best fountain itself through `/game/reincarnate` (today the game hangs: `EndTurn` throws while `awaitingReincarnation`).

## Requirements Reconciliation
Authority: `rules.md` + `.ai-factory/references/karak-regent-rules.md` > follow-up answers > existing implementation. Where the rules are silent (AI heuristics) the decision below is the contract.

Name corrections to the request (verified in code): Fateweaver belongs to the Oracle and is already auto-resolved for AI (`Field.php:981-992`); Astral Walking is the Wizard's; Lightning Attack is Markul's (Battle Mage); Dark Thorn is a Regent spell, not a hero skill.

Facts the plan relies on:
- Live AI = `src/src/Game/AI/SmartVirtualPlayer.php` (6385 lines) via `Api/Game/VirtualPlayerTurn/Action.php` (`POST /game/virtual-player-turn`); `EnhancedAIPlayer`, `ActionAdapter`, `ActionEnhanced`, `VirtualPlayer*`, `AIPlayerManager` are not reached from the FE — **not touched**.
- Writes go through `VirtualPlayerApiClient` (sub-requests); it has no methods for reroll-battle, reincarnate, magic-swap, lightning-attack, retreat, sprint, listen, tireless, heal-limit; `useSpell` exists but is never called.
- The AI never reads `getSkills()`. Server already auto-resolves for `isAi()` players: duels, arena, curse target/choice, Oracle Fateweaver, Dark-Thorn/duel reincarnation (`AutoReincarnate`).
- `GetAvailablePlacesForPlayer` already returns sprint and wall-walk (`Field.php:1708-1717`) options for a hero that has the skill.
- `Get/Action.php` already returns `hero`, `heroLabel`, `heroSkills`, `heroFlags`, `lordKarak` for every player including AI; FE portrait (`GameView.vue playerAvatar/figureHeroKey`), board figure (`GameTile.vue figureSrc`) and badge (`GameView.vue:416-428`) already render the hero when `player.hero` is set — only the "AI" fallbacks need review.
- `Player::setHealLimit` checks `$this->hero`, not `getSkills()` — a Lord Karak with Valduk's skill cannot set it (fixed in Task 2).

| Decision / supported combination | Source | Verification |
|---|---|---|
| **Random-heroes flag.** `CreateGame::$randomHeroes` (bool, default **false** so console commands and PHPUnit/e2e setup keep their behaviour) → `Game::$randomHeroes` (column, default false), `isRandomHeroes()`, `GameCreated::$randomHeroes`; API `POST /game/create` `randomHeroes`; `TestGameConfiguration`/`SetupTestGame` pass-through. Lobby sends `randomHeroes: localStorage.randomHeroes !== '0'` (default on). | follow-up | `RandomHeroAssignmentTest` |
| **Dealing.** In `Game::addPlayer`, when `command->hero === null && (isAi || randomHeroes)`: pool = `HeroType::cases()` minus `isRegent()` ones unless `$this->regent`, minus every hero already held by a player of this game (`GetPlayer(id)->getChosenHero()`); pick with `TestMode::getRandomArrayKey($pool, 'hero')` (`array_rand` normally, first in test mode); pool empty (cannot happen: 6 base heroes ≥ 5 players) → hero stays null with a WARN `error_log`. A supplied hero is kept as is (test setup pins heroes; human with switch off). `PlayerAdded::$hero` carries the dealt hero, so `Player::onPlayerAddedToGame` needs no change. `PickHero` is refused in a random-heroes game (`\DomainException`). | rules ("heroes dealt randomly"), follow-up | combos: base/regent × human/AI × switch on/off × hero supplied/not; distinctness with 2–5 players |
| **Lobby order.** The human joins first, then the AI → with the switch off the AI never duplicates the human's pick; with it on both are dealt from the shrinking pool. | HomeView.vue:1115-1121 | manual lobby check |
| **Rendering.** AI shows its hero portrait, board figure and badge (already data-driven); the name chip keeps "AI" but adds the hero short name; ActionLog/`getPlayerEmoji` keep 🤖 for the player name only. Fix the stale comment `GameView.vue:1331` ("AI players have no hero"). Monad theme: heroes have `image: null` → falls back to `ai.webp`, unchanged. Lobby: switch `random-heroes-toggle`; when on the hero carousel is replaced by a "hero is dealt at the start" card (`data-testid="hero-card-random"`). | request | `npm run build`, ESLint, manual |
| **Battle odds with skills (passive).** New pure class `src/src/Game/AI/BattleOdds.php`: exact 2d6 distribution adjusted by `HeroSkills`: `rerollsOnes` (die face 1 → uniform 2-6), `bearAttack` (1 → 6), `canRerollBattle` (P(win) = p + (1-p)·p), `winsTies` (total ≥ HP wins), flat bonuses `berserkBonus(hp)`, Farseeing +1 when no move spent yet, Sword Master (sword = 3, read from `ItemType`/`Player` damage helper used by `Battle`), consumables fireball +1 / frost fist +2 (Wizard keeps them → always counted), Sacrifice +1 if HP ≥ 2. Returns P(win), P(draw), P(loss). Replaces the body of `calculateDefeatProbability`, `calculateEffectiveStrength` bonus part and `estimateVictoryChance` (keep their signatures, delegate). Cursed (skills null) → plain 2d6. | rules.md hero sections | `BattleOddsTest` (each skill vs known exact numbers) |
| **After-roll decisions.** New pure class `src/src/Game/AI/HeroTactics.php` (stateless, input = `HeroSkills`, battle info array, HP, inventory) used by `handleBattleLossOrDraw`/`handleBattleWin`: 1) **Double Attack** — `canReroll` and not a win even with every consumable + sacrifice → reroll (`/game/reroll-battle`), re-evaluate the new `battleInfo`; 2) **consumables** — minimal set reaching **total > monster HP** (fixes the current off-by-one: today a draw uses none, a loss one short), or **total ≥ HP** for `winsTies`; frost fist before fireball when one frost fist suffices, else cheapest; 3) **Sacrifice** — `canSacrificeHp`, HP ≥ 2, and +1 turns draw→win or (with consumables) loss→win → `sacrificeHp: true`; 4) **Tireless** — win, loot not wanted (`shouldPickupItem` false) and moves left → finalize with `pickupItem: false`, call `/game/tireless`, continue the move loop; 5) **Unstoppable** — after any battle check `GetCurrentTurn`: if the turn is still open and moves remain, continue instead of `endTurn`; 6) **Lightning retreat** — on a non-win after Lightning Attack choose the retreat option closest to a fountain (`/game/retreat`). | rules.md, digest L19-20 | `HeroTacticsTest` (one case per branch) |
| **Turn-start decisions** (`HeroTactics::planTurnStart`, called in `executeTurn` after the stun/recovery checks, before the priority chain): in order, first that applies: a) **Reincarnation pending** (`player.awaitingReincarnation`, also re-checked after every battle) → `/game/reincarnate` to the discovered fountain nearest to the current strategic target (fallback: nearest to the player); b) **Portal of Healing** — teleport spell in inventory, (HP ≤ 2 or cursed) and not on a fountain → `useSpell(teleport, targetPosition = nearest fountain)`; c) **Dark Thorn** — for each dark thorn: target = rival with the highest treasure value, prefer one at 1 HP who is not a Warrior with skills (stuns him), never a 0-HP rival → `useSpell(dark_thorn, targetPlayerId)`; costs no move, loop over all thorns; d) **Magic Swap** — `canMagicSwap`, first action: swap with a rival standing on a fountain when own HP ≤ 2, or with a rival whose tile is adjacent to the dragon / an unopened chest the AI holds a key for, when the AI's own target is > 4 steps away; e) **Lightning Attack** — `lightningAttack`, first action: target = monster tile maximising value (dragon if P(win) ≥ 0.6, chest guardian with key, weapon upgrade) with `BattleOdds` P(win) ≥ `getMinAcceptableBattleProbability()`; f) **Sprint** — `sprints`, first action, strategic target (fountain when HP ≤ 2, chest with key, better weapon, dragon) reachable over discovered tiles at BFS distance 5-8 → `/game/sprint`, then normal moves (server returns sprint options in `GetAvailablePlacesForPlayer`). | rules.md, digest | `HeroTacticsTest` turn-start cases |
| **Movement decisions.** **Stealth** — `canSneakPastMonsters`: a monster tile on the path whose P(win) is below threshold is entered with `ignoreMonster: true` (`VirtualPlayerApiClient::movePlayer` gets the flag), and `canDefeatMonsterAt` no longer filters such tiles out. **Astral Walking** — `walksThroughWalls`: `findPathToTarget` BFS also uses orthogonal neighbours that are discovered tiles without a door (server already lists them as moveTo options). **Listening** — `listens`: the token of a new room is known right after placement; when it holds a monster with P(win) below the threshold, or any monster at 1 HP, call `/game/listen` instead of entering (one move); an empty room is entered (changed during implementation: the first rule, HP ≤ 2 → always listen, made her skip 83% fights). | rules.md, digest L22 | `HeroTacticsTest` movement cases; manual run |
| **Heal limit (Valduk).** `choosesHealing` and `healLimit === null` → once per game set it to 4 (keeps Berserk +1 at 3-4 HP); while cursed nothing (server heals fully). `Player::setHealLimit` switched to `getSkills()?->choosesHealing()` so a Lord Karak can use it. | digest L19 | `HeroTacticsTest`, `RegentHeroPlayerTest` addition |
| **Fateweaver / duels / curses.** Already server-side for `isAi()`; no AI change. | code | existing tests stay green |
| **Lord Karak AI.** Every check reads `getSkills()` (merged set), so a virtual Lord Karak uses all gained active skills through the same code. rules.md L135 "A virtual Lord Karak benefits from passive abilities only" is replaced. | request | `HeroTacticsTest` with a merged `HeroSkills` |
| **Out of scope.** `EnhancedAIPlayer` and the other dead AI classes; tile rotation/placement quality; duel tactics. | — | — |

## Commit Plan
- **Commit 1** (tasks 1-2): "feat(ai): deal random heroes, random-heroes game flag"
- **Commit 2** (tasks 3-5): "feat(ai): skill-aware battle odds and hero tactics for the virtual player"
- **Commit 3** (tasks 6-7): "test(ai): random heroes, battle odds and hero tactics"
- **Commit 4** (tasks 8-9): "feat(fe): random-heroes switch, AI hero rendering, docs"

## Tasks

### Phase 1: Dealing heroes (backend)
- [x] Task 1: Random-heroes flag and dealing in `Game::addPlayer`
  - `CreateGame.php`, `GameCreated.php`, `Game.php` (`randomHeroes` column, `isRandomHeroes()`, dealing per the table, `private dealHero(MessageContext): ?HeroType`), `Api/Game/Create/Request.php` + `Action.php`, `Testing/TestGameConfiguration.php`, `Testing/SetupTestGameHandler.php`, `Api/Testing/SetupTestGame/Request.php` + Action, `fe/e2e/utils/test-game-setup.ts` (optional `randomHeroes`, pass-through only).
  - `Player::pickHero` / `Api/Game/PickHero/Action.php`: refuse when `GetGame->isRandomHeroes()`.
  - Migration via `php bin/console doctrine:migrations:diff` in the php container: keep only `game ADD random_heroes BOOLEAN DEFAULT false NOT NULL`, strip the known `unique_player_wallet` drift; migrate; `doctrine:schema:validate`.
  - Logging: `error_log('DEBUG AI: dealt hero …')` is not used here (domain) — the `PlayerAdded` event carries the hero and is logged by the bus; WARN `error_log` only on an empty pool.
- [x] Task 2: `Player::setHealLimit` via `getSkills()` (independent)
  - `Player.php:317` → `getSkills()?->choosesHealing()`; cursed Valduk keeps the current refusal semantics (check what it does today and keep it).

### Phase 2: AI decisions
- [x] Task 3: `BattleOdds` (pure) and wiring into the evaluators (depends on —)
  - New `src/src/Game/AI/BattleOdds.php` per the table: `winChance(int $monsterHp, ?HeroSkills $skills, int $weaponDamage, int $hp, int $fireballs, int $frostFists, bool $firstMove): array{win: float, draw: float, loss: float}` plus `bonus(...)`. Weapon damage computed the way `Battle` does (Sword Master included) — reuse the player's damage helper instead of the local dagger/sword/axe table at `SmartVirtualPlayer.php:4672-4692`.
  - `SmartVirtualPlayer`: `calculateDefeatProbability` (`:1622`), `calculateEffectiveStrength` (`:4663`), `estimateVictoryChance` (`:5340`), dragon max-roll checks (`:598`, `:616`, `:767`) delegate to `BattleOdds`; read skills once per turn into `$this->skills` (`GetPlayer->getSkills()`), and `firstMove = moveCount === 0`.
  - Logging: one `ai_reasoning` action per battle evaluation that changed because of a skill (`reason: 'skill: bearAttack'` etc.).
- [x] Task 4: `VirtualPlayerApiClient` methods for the active skills (independent)
  - Add `rerollBattle`, `reincarnate(targetPosition)`, `magicSwap(targetPlayerId)`, `lightningAttack(targetPosition)`, `retreat(position)`, `sprint`, `listen(position)`, `tireless`, `setHealLimit(?int)`; `finalizeBattle` gets `sacrificeHp`; `movePlayer` gets `ignoreMonster` passed through; `useSpell` checked against `UseSpell/Request.php` (`spellId`, `targetPosition`, `targetPlayerId`). Same sub-request pattern and error shape as the existing methods.
- [x] Task 5: `HeroTactics` (pure) and the `SmartVirtualPlayer` integration (depends on 3, 4)
  - New `src/src/Game/AI/HeroTactics.php`: `afterRoll(...)` (reroll / consumables / sacrifice decision object), `afterWin(...)` (tireless), `planTurnStart(...)` (reincarnate, portal, dark thorn list, magic swap, lightning, sprint, heal limit), `shouldSneak(...)`, `shouldListen(...)`, `pickRetreat(...)`, `pickFountain(...)` — inputs are plain arrays/values so tests need no API client.
  - `SmartVirtualPlayer::executeTurn`: call `planTurnStart` after the stun/recovery block (`:183-233`), execute its steps through the client, then continue the priority chain (Lightning/Magic Swap consume the turn's moves → handle the battle result / end turn like after a normal battle).
  - `handleBattleLossOrDraw` (`:3827`) / `handleBattleWin` (`:2963`): use `afterRoll` / `afterWin`; fix the consumable off-by-one; after finalize, if `awaitingReincarnation` → reincarnate before `endTurn`; Unstoppable/Tireless: continue via `continueAfterAction` instead of `endTurn` when `GetCurrentTurn` is still open and moves remain (`continueAfterAction` `:3121/3157` must stop treating "a battle occurred" as terminal in that case).
  - Movement: `canDefeatMonsterAt` (`:1686`) honours Stealth; `findPathToTarget` (`:6088`) honours Astral Walking; tile exploration (`executeTilePlacement` `:2256`) checks `shouldListen`.
  - Logging: each skill decision → `createAction('ai_reasoning', ['decision' => 'use_skill', 'skill' => …, 'reason' => …])` and one `error_log('DEBUG AI: skill …')`.

### Phase 3: PHPUnit
- [x] Task 6: Dealing tests (depends on 1, 2)
  - `src/tests/Game/GameLifecycle/RandomHeroAssignmentTest.php` (harness of `LordKarakGameTest`, stub `GetPlayer`): AI without hero in a base game gets a base hero; in a Regent game any hero; never a hero already held; random switch on → human dealt too; switch off → human's supplied hero kept, AI gets another; supplied Regent hero in a base game still refused; 5 players → 5 distinct heroes; `PickHero` refused in a random game; test mode → first hero of the pool.
  - `RegentHeroPlayerTest`: Lord Karak with Valduk in the set can set the heal limit.
- [x] Task 7: AI logic tests (depends on 3, 5)
  - `src/tests/Game/AI/BattleOddsTest.php`: plain 2d6 vs HP 7 = 15/36 win; `rerollsOnes`, `bearAttack`, `winsTies`, `canRerollBattle`, berserk at 3 and 1 HP, Farseeing, sword 3 for Markul, frost fist +2, merged Lord Karak set, cursed (null) = plain.
  - `src/tests/Game/AI/HeroTacticsTest.php`: reroll only when losing; minimal consumables (draw → one fireball; tie with `winsTies` → none); sacrifice turns a draw into a win, never at 1 HP; tireless when loot unwanted; dark thorn target choice (leader, 1-HP non-Warrior preferred, 0-HP skipped); portal at HP ≤ 2; magic swap onto a fountain; lightning target respects the threshold; sprint only at distance 5-8; listen when weak; retreat/fountain choice; heal limit set once to 4.
  - Run `--filter 'AI|Hero|Regent|LordKarak|Battle|Field|Player|Game'`, then the full suite.

### Phase 4: Frontend and docs
- [x] Task 8: Lobby switch and AI hero rendering (depends on 1)
  - `fe/src/services/api.js` `createGame` option `randomHeroes`.
  - `fe/src/views/HomeView.vue`: `random-heroes-toggle` (localStorage `randomHeroes`, default on); when on hide the hero carousel and show the `hero-card-random` card, join the human with `hero: null`; when off keep the current picker.
  - `GameView.vue`: name chip "AI · {heroShortName}" for an AI with a hero; fix the comment at `:1331`; check `GameTile.vue` figure and `BattleReportModal.vue:1592` battle sprite use the hero for an AI (they key on `player.hero`).
  - Verify by grep that no hero UI is gated on "is current user" for display (skill buttons stay for the current user only).
  - `npm run build` + ESLint (`task fe-lint`).
- [x] Task 9: Docs (depends on 5, 8)
  - `rules.md`: hero section — "heroes are dealt randomly (lobby switch, on by default; off → you pick, the AI is dealt one of the rest)"; replace "AI players have no hero" (L148) and "A virtual Lord Karak benefits from passive abilities only" (L135) with the list of skills and spells the AI uses.
  - `fe/src/views/RulesView.vue` same paragraph; README feature bullet.

## Verification notes (2026-10-15)
- Magic Swap also goes next to an unopened chest when the AI holds a key and every chest is more than 4 steps away (added after verify).
- Stealth decisions are recorded as `ai_reasoning` actions (collected in `SmartVirtualPlayer::$skillActions`, appended to the turn's actions).
- The "AI · hero" name chip was not added: the hero badge next to "AI" already names the hero; the portrait alt text names it too.
- Lightning Attack is never used at 1 HP (added after the API smoke run: Markul kept striking at 1–2 HP).
- Fixed on the way: undefined `$recursionDepth` in four `SmartVirtualPlayer` methods (the AI turn errored), missing `GetActivePlayers` import (opponent scores were always empty, which skewed the dragon decision).
- Live API smoke (≈20 games): Dark Thorn, Portal of Healing, heal limit, Tireless, Sacrifice, Double Attack, Lightning Attack + retreat, Listening seen; Sprint, Magic Swap, Reincarnation, Unstoppable, Stealth covered by PHPUnit only. Lobby not checked in a browser.
