# Monadungeon

A fully-featured multiplayer dungeon-crawling adventure game where players explore a labyrinth, fight monsters, collect treasures, and race to defeat the final boss.

🎮 **[Play Now at monadungeon.xyz](https://monadungeon.xyz)**
📺 **[Watch Gameplay Video](https://www.youtube.com/watch?v=BEVkE--cnkc&feature=youtu.be)**

## Game Features

### Authentication
- **Guest Play**: Jump straight into the game without creating an account
- **Privy Integration**: Secure Web3 authentication with wallet connection and social logins
- **Multiple Login Options**: Connect via MetaMask, email, or social accounts through Privy

### Core Gameplay
- **Multiplayer Support**: 1-4 players taking turns in clockwise order
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