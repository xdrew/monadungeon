# Monadungeon - A Dungeon Crawler Game

This is the frontend for Monadungeon, a 2D dungeon crawler game built with Vue.js and Phaser 3.

## Project Overview

Monadungeon is a turn-based dungeon crawler game where players navigate through procedurally generated rooms, encountering monsters such as skeleton kings, giant rats, and the fearsome dragon. The game features treasure hunting, battling monsters, and ultimately seeking to defeat the dragon.

## Themes

The flavour of the UI (monster and hero names and pictures, logo, app name, colour palette, sign-in) is a **build-time theme** selected with `VITE_THEME`:

| `VITE_THEME` | Look |
|---|---|
| `base` (default) | Karak board game: Giant Rat, Skeleton King, Dragon…, hero portraits, no sign-in |
| `monad` | Monad Testnet flavour: Monad mascots as monsters, Monad logo, purple palette, Monad Games ID sign-in |

```bash
# base theme (default)
npm run build

# Monad theme
VITE_THEME=monad npm run build

# production image: VITE_THEME is a build arg of frontend-builder (compose.prod.yml / fe/Dockerfile.prod)
VITE_THEME=monad docker compose -f compose.prod.yml build frontend-builder
```

Theme data lives in `fe/src/theme/` (`base.js`, `monad.js`); images in `fe/public/themes/<theme>/`. The base theme's webp files are not in the repo yet — until they are added, the UI shows emoji instead (see `fe/public/themes/base/README.md` for the 15 expected file names).

## Prerequisites

- Node.js (v14.x or higher)
- npm (v6.x or higher)

## Project Setup

```bash
# Install dependencies
npm install

# Serve with hot-reload for development
npm run dev

# Build for production
npm run build

# Lint and fix files
npm run lint

# Build with the Monad flavour instead of the default Karak (base) theme
VITE_THEME=monad npm run build
