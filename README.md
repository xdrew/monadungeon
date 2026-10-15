# Monadungeon

A fully-featured multiplayer dungeon-crawling adventure game where players explore a labyrinth, fight monsters, collect treasures, and race to defeat the final boss.

🎮 **[Play Now at monadungeon.avelent.work](https://monadungeon.avelent.work)**
📺 **[Watch Gameplay Video](https://www.youtube.com/watch?v=BEVkE--cnkc&feature=youtu.be)**

## Game Features

### No sign-in
- **Guest Play**: Jump straight into the game without creating an account

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

## Game Rules

See [rules.md](rules.md) for complete game rules and mechanics.

## API Documentation

The game provides RESTful API endpoints for all game actions. See [api.http](api.http) for examples and usage.