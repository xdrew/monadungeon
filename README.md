# Monadungeon

A fully-featured multiplayer dungeon-crawling adventure game where players explore a labyrinth, fight monsters, collect treasures, and race to defeat the final boss.

🎮 **[Play Now at monadungeon.avelent.work](https://monadungeon.avelent.work)**
📺 **[Watch Gameplay Video](https://www.youtube.com/watch?v=BEVkE--cnkc&feature=youtu.be)**

## Game Features

### Guest accounts
- **A name, no password**: the first visit asks for a name and keeps a guest account in a session cookie; the name can be changed later with the pen on the start screen
- **Your seats are yours**: the server refuses anyone else acting for them
- **Keep it with Google**: "Keep with Google" next to your name ties the guest account to a Google account; "I have an account: sign in with Google" brings it to another device (the start screen and invite links). A kept account can sign out on a device
- **Hall of fame**: by players and by heroes

### Online tables
- **Open a table** on the start screen: 2–5 seats, the same options (Regent, Lord Karak, random heroes); it shows in everyone's list of open tables, and its invite link (`/table/{id}`) seats a friend after a name
- **The waiting room**: who sits where, one hero per table, the host seats the AI and starts once two seats are taken and every seat holds a hero; the host leaving closes the table
- **Live**: every browser at the table sees the others' tiles, walks, fights and chronicle lines as they happen (push through Mercure); the server plays the AI seats itself and every browser plays the AI's turn back
- **Time for a turn**: the host picks 1, 2 or 5 minutes (or no limit); the banner counts down. A turn that runs out is played by the AI, and the seat is *away*: the AI takes its turns until its player taps "Take my seat back" (or opens the game again). When everyone is away the game waits
- "Descend against the AI" stays a one-browser game

### Core Gameplay
- **Multiplayer Support**: 1-5 players; the dice pick who starts, then turns go clockwise
- **Dynamic Tile Exploration**: Procedurally generated dungeon layout as players explore
- **Turn-Based Combat**: Roll dice to battle monsters with weapons and spells
- **Inventory Management**: Collect and manage keys, weapons, spells, and treasures
- **Victory Points System**: Compete to collect the most points from treasure chests

### Implemented Mechanics
- **Movement System**: 4 actions per turn for exploration and movement
- **Battle System**: Automatic combat with dice rolls, weapon bonuses, and spell modifiers
- **Tile Placement**: Smart tile generation with proper corridor connections
- **Special Tiles**: Healing fountains and teleportation gates for strategic gameplay
- **Player States**: HP management, stunning/defeat mechanics with recovery
- **Hero Skills**: Heroes are dealt at random (lobby switch, on by default; off — pick your own, the AI is dealt another), and the AI plays its hero's skills and spells — Horan the Warrior rerolls the dice once per fight (Double Attack) and reincarnates at a healing fountain instead of falling unconscious (Reincarnation); Aderyn the Thief wins tied fights (Backstab) and can sneak past monsters (Stealth); Victorius the Swordsman rerolls every 1 on the dice (Combat Training) and keeps his turn going whenever he rolls a 6 (Unstoppable); Lord Xanros the Warlock pays 1 HP for +1 strength after the roll (Sacrifice) and can trade places with any hero as the first action of his turn (Magic Swap); Argentus the Wizard keeps his fireballs after using them in a fight (Magical Affinity) and walks through walls onto any adjacent discovered tile (Astral Walking); Taia the Oracle gets +1 when she fights before spending a move (Farseeing) and draws two tokens for every new room, placing one and returning the other (Fateweaver). With the Karak: Regent expansion: Valduk the Barbarian (Berserk, Tireless), Baron Markul the Battle Mage (Sword Master, Lightning Attack), Hanna the Acrobat (Dagger Throwing, Sprint) and Lady Lorraine the Ranger (Bear Attack, Listening). Defeating a mummy lets you curse a rival, whose hero loses all skills until healed at a fountain or by a Portal of Healing
- **Karak: Regent expansion** (toggle in the lobby): Battle Arena duels, Cursed Rooms, Giant Bats with Dark Thorn and Skeleton Mages with Frost Fist; optional Lord Karak variant — at the fifth Lord Karak room the weakest hero becomes Lord Karak with the abilities of every other hero
- **Item System**: Weapons (dagger/sword/axe), consumable spells, keys, and treasures
- **Win Conditions**: Game ends when final boss is defeated, winner has most victory points

## Technical Stack

### Backend
- **Symfony Framework** with Domain-Driven Design (DDD) architecture
- **PostgreSQL** database with JSONB support for flexible data storage
- **Custom Message Bus** with attribute-based handlers for CQRS pattern
- **RoadRunner** as high-performance PHP application server
- **Docker** containerization for all services
- **Mercure** hub (`mercure` service, SSE behind nginx at `/.well-known/mercure`) pushes game changes to the browsers; the backend signs its publish JWT with `MERCURE_JWT_SECRET` (`.env`; production sets its own in the environment of `compose.prod.yml`) and posts to `MERCURE_URL`
- **turnwatch** service (`php bin/console app:turn-watch`, a loop every 5 s) lets the AI play the online turns that ran out of time; production needs it as well as `MERCURE_URL` / `MERCURE_JWT_SECRET` in `.env.prod`

### Frontend
- **Vue 3** with Composition API for reactive UI
- **Phaser 3** game engine for rendering and interactions
- **Vite** for fast development and hot module replacement
- **Playwright** for E2E testing with controlled game scenarios

### Architecture Highlights
- **Package by Feature**: Domain logic organized in `src/Game/` with co-located commands, events, and handlers
- **Message Bus Middleware**: Automatic logging, transactions, outbox pattern, and deduplication
- **API Design**: RESTful endpoints with invokable controllers and type-safe DTOs
- **Test Mode**: Backend supports controlled randomness for predictable E2E tests
- **Theme: Torchlight**: fonts and palette in `fe/src/styles/design-tokens.css` (see [Theme](#theme))

## Theme

The game has one look, **Torchlight**: a dark dungeon lit by torches, Pirata One for headings and Spectral for text (self-hosted through `@fontsource`), an ember/bone palette, iron-ring buttons and line icons instead of emoji.

- Fonts, palette, radii and shadows: `fe/src/styles/design-tokens.css` (`--font-display`, `--font-body`, `--bg-*`, `--text-*`, `--color-primary` ember, `--iron*`)
- Buttons: `fe/src/styles/components/buttons.css` (`.btn` + `.btn--ember` / `.btn--ghost` / `.btn--blood` / `.btn--iron`)
- Icons: `fe/src/components/ui/GameIcon.vue` (`<GameIcon name="heart" />`, set in `gameIcons.js`)
- The field: 150px tiles (`TILE_SIZE` in `fe/src/utils/fieldUtils.js`, `--tile-size`, one tile of padding around the field); `TorchlightLayer.vue` dims the dungeon outside the heroes' torches (the acting hero carries the widest light and a warm halo, nothing is dimmed once the game is over); monsters and rewards are free tokens with an ember-lit HP number, heroes are standees (blue ground mark for you, ember for the acting hero); move marks are ember frames, unexplored doorways dark slots with the explore mark
- The HUD (`fe/src/components/game/hud/`, `GameHud.vue` floats over the full-bleed field): the turn banner on top (its `<h3>` "Turn: N" is the page's first h3 — the e2e reads it), the turn-order rail on the left (`PartyRail.vue`, order from `useTurnOrder.js`: medallions, hearts, treasure, hero badges; click a medallion for that hero's kit), "Your pack" bottom left (spells are cast from it), contextual iron rings bottom right (`IronRing.vue`: hero skills, heal limit, Journal, End Turn; off turn a dim Wait ring and "Your turn comes after …"), the chronicle top right and the Journal drawer (sentences from `fe/src/utils/chronicle.js`), the decision plaque over the rings for choices (portal, Dark Thorn, duel, curse, retreat, an item found, a full pack, a chest without a key, Stealth, Fateweaver …), the AI playback / replay plaque under the banner. On phones and short landscape screens the rail becomes a strip of small medallions, pack and rings a bottom bar, the chronicle hides behind the Journal ring. `.end-turn-btn` is an e2e hook only: exactly one is visible on your turn in a base game
- The fight (`fe/src/components/game/BattleReportModal.vue` with `fe/src/components/game/fight/`: `DieFace`, `FighterFigure`, `ConsumableCard`) is a full-screen scene: the hero left in cold light, the monster (or the duel rival) mirrored right in ember light, the dice and the two totals between them with the breakdown of the roll and the skill notes, and below a choice plaque with spells, the reward and the buttons. The HUD fades while it is open. Lord Karak's reveal is a cinematic of its own (`LordKarakRevealModal.vue`). The lobby (`HomeView.vue`) is the dungeon art full-bleed with the title on the left and "Prepare the descent" on the right (hero, options, the descent button, How to play / Hall of fame); the rules (`RulesView.vue`) are a book with a sticky contents list (section anchors `#overview` … `#tips`); the leaderboard is the Hall of fame; 404 in the same look. The e2e hooks stay: `.battle-overlay`, `.battle-footer`, `.close-btn`, `.dice-face`, `.item-chip.selectable`, `.btn-defeat`/`.btn-draw`/`.btn-reroll`, `.victory-title`/`.defeat-title`, the skill-note testids and the button texts
- Monster and hero names and images: `fe/src/theme/base.js`; images in `fe/public/themes/base/`. If an image is missing the UI falls back to an emoji (`ThemedImage.vue`).

### Art generation

The base-theme monsters, hero portraits and the lobby background are generated with Gemini by `tools/art` (Python, runs on the host):

```bash
python3 -m venv tools/art/.venv && tools/art/.venv/bin/pip install -r tools/art/requirements.txt
cp tools/art/.env.example tools/art/.env        # put GEMINI_API_KEY there (gitignored; never into the root .env)
tools/art/.venv/bin/python -m tools.art probe    # one test call
tools/art/.venv/bin/python -m tools.art generate # missing assets → fe/public/themes/base/
tools/art/.venv/bin/python -m tools.art generate --only dragon --force   # redo one
```

Style consistency comes from a committed style bible (`tools/art/style/style.json`, `STYLE.md`): every call sends the existing item icons/figures as style references plus the most recently generated sprites, and `tools/art/generated.json` records the model, prompt hash and references per asset. Details in `tools/art/README.md`.

### Music and sounds

The music follows the situation with crossfades: the lobby (warm, tabletop), the dungeon (dark and calm, two tracks alternating), the fight, the dragon's fight, and short stings for a won or lost fight, the end of the game and Lord Karak. About 40 sounds cover the interface and every action (tiles, steps, dice, blows, chests, items, spells, the curse, turns, the online time limit); the AI's and an online rival's moves sound too. The sound ring on every page sets the music and the sounds apart and mutes.

They are generated by `tools/audio` (Lyria through the Gemini API for the music, ElevenLabs for the sounds), loudness-matched with ffmpeg:

```bash
python3 -m venv tools/audio/.venv && tools/audio/.venv/bin/pip install -r tools/audio/requirements.txt
cp tools/audio/.env.example tools/audio/.env    # GEMINI_API_KEY, ELEVENLABS_API_KEY (gitignored)
tools/audio/.venv/bin/python -m tools.audio generate --only fight --force
```

Details, costs and licensing in `tools/audio/README.md`.

## Localization

The UI speaks English, Russian and Polish (vue-i18n 9, `fe/src/i18n/`). The language comes from `localStorage.locale`, else the browser (`ru*` → ru, `pl*` → pl, else en); the EN/RU/PL ring in the lobby and in the HUD switches it and remembers the choice. `<html lang>` and the page title follow it.

- Messages: `fe/src/i18n/locales/{en,ru,pl}.js`, one nested object per area (`lobby`, `hud`, `game`, `battle`, `chronicle`, `rules`, …). en is the source; plurals are `one | other` in en and `one | few | many` in ru/pl.
- Names: `fe/src/i18n/names.js` — `monsterName(key, form)`, `heroName(hero, 'name'|'short'|'role')`, `itemName(type, form)`, `skillText(skill)`. Monsters and items have grammatical forms for sentences (`nom`, `acc`, `ins`); a sentence picks the form it needs instead of gluing words together.
- Terms: `.ai-factory/references/karak-glossary.md` (official Albi Polska and «Звезда» rulebook names, our translations marked). New names go there first.
- Hero skills carry a stable `key` from the backend (`HeroType::skills()`); the UI translates `skills.<key>.*`. Server error texts stay English inside a translated frame.
- The e2e runs in English (`locale: 'en-US'` in the Playwright configs) and matches English button texts: keep those en strings verbatim. Loading statuses stay English as the `data-loading-status` hook; `LoadingOverlay` shows them translated.
- Cyrillic headings: Pirata One has no Cyrillic, so `--font-display` falls back to Ruslan Display for it.
- Inline markup inside a sentence: `<I18nT scope="global" keypath="…">` imported from `vue-i18n` with named slots (the kebab `<i18n-t>` tag is treated as a custom element by `vite.config.js`); no `v-html`.
- Check: `npm run i18n:check` (in the frontend container) compares ru/pl keys, `{placeholders}` and plural forms with en.

## Quick Start

### Prerequisites
- Docker and Docker Compose
- Task (taskfile.dev) for running commands

### Setup
```bash
# Full project initialization with Docker
task init

# Start all services (PostgreSQL, RabbitMQ, PHP, Frontend)
task run
```

### Development
```bash
# Backend development
task php          # Enter PHP container
task all          # Run PHPUnit tests
task fix          # Run code quality tools

# Frontend development
task fe-dev       # Start Vite dev server
task fe-build     # Production build
task fe:test      # Run E2E tests
```

### Google sign-in setup
1. Google Cloud console → APIs & Services → Credentials → *Create credentials* → *OAuth client ID*, type **Web application**.
2. Authorized redirect URI: `https://<your host>/api/account/google/callback` (the server builds it from the request's host, `X-Forwarded-Proto` and `X-Forwarded-Port`).
3. Put `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` into `.env.prod` (production) or `.env` (dev). With an empty id the Google buttons are hidden and the endpoints answer 404.
4. Dev and test use `GOOGLE_CLIENT_ID=fake`: no Google at all, `/api/account/google/start?as=<sub>:<email>` signs in as anyone (the e2e does it through `localStorage.googleFakeAs`).

## Game Rules

See [rules.md](rules.md) for complete game rules and mechanics.

## API Documentation

The game provides RESTful API endpoints for all game actions. See [api.http](api.http) for examples and usage.