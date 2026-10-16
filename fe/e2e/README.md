# Karak e2e tests (Playwright)

The specs drive the real app: the Vue frontend served through nginx at `http://sf.sf:18080` and the Symfony backend in
its **test mode**, which scripts the dice, the tile deck and the bag so every fight and every room is known in advance.

## Specs

| Spec | What it covers |
|---|---|
| `tests/two-player-game.spec.ts` | The showcase: a whole game, Warrior (Double Attack, Reincarnation) against Thief (Stealth, Backstab): fights won, lost and drawn, stun, spells, a full pack, keys, teleportation gates, fountains, the dragon. Its video is the showcase recording. |
| `tests/pages.spec.ts` | The lobby fitting a 1280×720 screen (the AI and Online tabs, no page scroll), the lobby options and the hero roster, the language switch (en/ru/pl), the rules contents, the Hall of fame, 404, a real game against the AI with its playback speeds (no test mode). |
| `tests/base-heroes.spec.ts` | Swordsman (Combat Training, Unstoppable), Warlock (Sacrifice, Magic Swap), Wizard (Magical Affinity, Astral Walking), Oracle (Fateweaver, Farseeing). |
| `tests/regent-heroes.spec.ts` | Barbarian (Berserk, heal limit, Tireless), Battle Mage (Sword Master, Lightning Attack, the retreat), Acrobat (Dagger Throwing, Sprint), Ranger (Bear Attack, Listening). |
| `tests/mechanics.spec.ts` | The Curse of Karak, the Portal of Healing, chests with and without a key, a find a full pack does not want, tile rotation, a fight surviving a reload, the dragon, the results and the Hall of fame. |
| `tests/online.spec.ts` | Guest accounts: the name card, rename, the seat guard (another browser context gets 403), Google (fake provider: keep, sign in on another device, sign out, a created account, an invite link), the Hall of fame by players and heroes after a won game. |
| `tests/online-table.spec.ts` | Online tables: two browsers (the open-table list, the waiting room live, one hero per table, the start, each other's tile/turn without a reload, the seat guard), an AI seat the server plays and both browsers play back, invites to a full and to a closed table, a 5-second turn that runs out (the AI plays it, the seat is away, "Take my seat back"). |
| `tests/audio.spec.ts` | Sound: the sound ring (music and sounds apart, kept after a reload, mute), music by situation (lobby, explore, fight and back), the cues of a turn in order (tile, step, roar, dice, blow, pickup, end of turn, your turn), an AI turn played back on an online table. Headless Chromium is silent: the dev build's cue log `window.__audioCues` is checked. |
| `tests/regent-mechanics.spec.ts` | Giant Bat → Dark Thorn, Skeleton Mage → Frost Fist, the Battle Arena duel, the Cursed Room, the Lord Karak reveal. |

Each topic spec has one `test()` per hero or mechanic and its own game, so a failure stays local.

## Running

The dev stack must be up (`task run`) and Vite must be serving (`task fe:dev`); nginx answers 502 otherwise.

```bash
task fe:test:spec -- mechanics.spec.ts          # one spec, verbose list output
task fe:test:spec -- base-heroes.spec.ts -g Oracle   # one test by name
task fe:test:video                              # everything, with videos
```

The runner is the `playwright` service of `docker-compose.test.yml` (config `playwright.config.docker.ts`, one worker,
1280×720, video on). Results land in `fe/test-results/`.

## Test mode

`utils/test-game-setup.ts` → `POST /api/test/setup-game` (dev/test only):

- `diceRolls`: one queue for every roll of the game, in order (Combat Training's rerolled 1s, the Cursed Room die
  and the duel rolls take from it too);
- `tileSequence`: the deck after the start tile: names (`fourSideRoom`, `threeSide`, `twoSideStraight`,
  `battleArena`, `cursedRoom`, …) or `{ orientation, room, features }`;
- `itemSequence`: the bag (`giant_rat`, `mummy`, `dragon`, `treasure_chest`, `giant_bat`, `skeleton_mage`, …);
- `playerConfigs`, keyed by player id in turn order: `hero`, `maxHp`, `hp` (current HP), `maxActions`,
  `inventory` (item types already in the pack: `sword`, `fireball`, `teleport`, `key`, `dark_thorn`, …, or
  `{ type, treasureValue }`), `accountId` (the seat belongs to that guest account: create it first with
  `page.request.post('/api/account/guest')` and act through `page.request` — the seat guard checks the cookie);
- `regent`, `lordKarak`, `randomHeroes`.

Online tables are scripted at creation instead: `page.request.post('/api/tables', { data: { seats, test: { tileSequence, itemSequence, diceRolls } } })` (dev/test only). Who starts is rolled for: read it from the API.

Positions, stun and the curse come from play. The first player starts; placing a tile moves the hero onto it.

## Helpers

- `utils/hotseat.ts`:
  - `startGame()` (test mode on, game set up and opened, everyone ready);
  - `switchTo()` (hand the browser to the other player);
  - `placeAt(x, y)` / `moveTo(x, y)` (a given spot);
  - `waitForFight()`, `takeReward()`, `strikeWith()` (spells / Sacrifice, then "fight, win and pick up");
  - `endTurn()`;
  - API readers: `apiGame`, `apiPlayer`, `position`, `packTypes`, `packItemId`;
  - `visibleWithin()`: `isVisible()` never waits.
- `watchPage()` prints the page's errors and 5xx answers; run with `-e E2E_CONSOLE=1` (and `VERBOSE=1`) to print the whole browser console.
- `utils/game-helpers.ts`: the showcase's helpers. `placeTile()` ignores its x/y and takes the first free spot.
- `utils/screens.ts` — `snap(page, spec, name, { phone })`: every screen a spec reaches goes to
  `fe/test-results/screens/<spec>/NN-<name>.png`, and with `phone` also `.phone.png` at 390×844, for design reviews.
