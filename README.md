# Monagungeon 

## Key Features

Currently, the project functions as a field management system for the game. It provides the following features:
- Creates a field for gameplay.
- Generates a tile deck with predefined tiles.
- Draws a tile from the deck.
- Places the tile on the field according to game rules.
- It also prints the entire field to console

## Technical Implementation

The project is built on top of the Symfony framework using the Domain-Driven Design (DDD) approach. The structure follows a "package by feature" methodology without using explicit layer directories.

A custom message bus is implemented, allowing any class method to become a message handler by adding an attribute. This provides flexibility beyond what Symfony Messenger offers. Additionally, it includes several useful middlewares:
- Logging
- Transaction handling
- Outbox pattern
- Deduplication

## Quick Start

Run the following command to set up the project:

```bash
make init
```

## API

This project includes a set of API endpoints showcasing the implementation approach, such as:
- Invokable classes
- Custom Request and Response handling

See more details and examples in the [api.http](api.http) file.