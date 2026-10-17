<?php

declare(strict_types=1);

namespace App\Game\Turn;

enum TurnAction: string
{
    // Basic movement actions
    case MOVE = 'move';
    case DISCOVER_TILE = 'discover_tile';
    case USE_TELEPORT = 'use_teleport';

    // Tile actions
    case PLACE_TILE = 'place_tile';
    case PICK_TILE = 'pick_tile';
    case ROTATE_TILE = 'rotate_tile';

    // Combat actions
    case FIGHT_MONSTER = 'fight_monster';
    case USE_SPELL = 'use_spell';
    case USE_HERO_ABILITY = 'use_hero_ability';

    // Warlock "Magic Swap": trade places with another hero, costs the whole turn's moves
    case MAGIC_SWAP = 'magic_swap';

    // Oracle "Fateweaver": chose which of the two drawn tokens is placed on the new room (not a move)
    case FATEWEAVER = 'fateweaver';

    // Curse of Karak: the mummy slayer placed (or kept) the curse token (not a move, the Game validates it)
    case CURSE = 'curse';

    // Karak: Regent — bookkeeping, never a move: a Cursed Room roll, a Battle Arena duel, the duel loot taken
    case CURSED_ROOM = 'cursed_room';
    case DUEL = 'duel';
    case DUEL_LOOT = 'duel_loot';

    // Karak: Regent heroes
    // Valduk "Tireless": left the loot of a won fight and keeps moving (the step into the fight is paid now)
    case TIRELESS = 'tireless';

    // Baron Markul "Lightning Attack": teleport onto a monster for all 4 moves
    case LIGHTNING_ATTACK = 'lightning_attack';

    // Baron Markul: the retreat chosen after a Lightning Attack that was not won (bookkeeping, never a move)
    case RETREAT = 'retreat';

    // Karak: Regent, Lord Karak variant — the weakest hero became Lord Karak (bookkeeping, never a move)
    case LORD_KARAK = 'lord_karak';

    // Hanna "Sprint": up to 8 moves this turn, no tile exploration (bookkeeping, never a move)
    case SPRINT = 'sprint';

    // Lady Lorraine "Listening": explored the adjacent tile just placed without entering it (one move)
    case LISTEN = 'listen';

    // Item interactions
    case PICK_UP_EQUIPMENT = 'pick_up_equipment';
    case UNLOCK_CHEST = 'unlock_chest';
    case HEAL_AT_FOUNTAIN = 'heal_at_fountain';
    case PICK_ITEM = 'pick_item';

    // End turn action
    case END_TURN = 'end_turn';

    public function shouldEndTurn(): bool
    {
        return \in_array($this, [
            self::PICK_UP_EQUIPMENT,
            self::UNLOCK_CHEST,
            self::HEAL_AT_FOUNTAIN,
            // self::PLACE_TILE, // Turn ending handled manually by frontend after movement
            // self::PICK_ITEM, // Removed - turn ending handled manually in Field.php
            self::END_TURN,
            // self::FIGHT_MONSTER, // No longer automatically ends turn
        ], true);
    }

    /**
     * Alias for shouldEndTurn() for better readability in some contexts.
     */
    public function isEndOfTurn(): bool
    {
        return $this->shouldEndTurn();
    }

    public function consumesMove(): bool
    {
        return \in_array($this, [
            self::MOVE,
            self::DISCOVER_TILE,
            self::USE_TELEPORT,
            self::MAGIC_SWAP,
            self::TIRELESS,
            self::LIGHTNING_ATTACK,
            self::LISTEN,
        ], true);
    }

    /**
     * How many of the turn's moves the action spends (Warlock "Magic Swap" and Markul "Lightning Attack" cost all 4).
     */
    public function moveCost(): int
    {
        return $this === self::MAGIC_SWAP || $this === self::LIGHTNING_ATTACK ? 4 : 1;
    }

    public function isIncreasesActionCounter(): bool
    {
        return \in_array($this, [
            self::MOVE,
            self::USE_TELEPORT,
            self::MAGIC_SWAP,
            self::TIRELESS,
            self::LIGHTNING_ATTACK,
            self::LISTEN,
            // Note: PICK_TILE, PLACE_TILE, ROTATE_TILE do NOT increase action counter as they are part of exploration
            // Note: Combat and item actions don't count - they end the turn instead
        ], true);
    }

    public function isAllowedAfter(?self $previousAction): bool
    {
        // Warlock "Magic Swap" costs all 4 moves, so it can only open the turn
        if ($this === self::MAGIC_SWAP) {
            return $previousAction === null;
        }

        // Curse of Karak: bookkeeping after a won fight, the Game aggregate checks that a choice is pending
        if ($this === self::CURSE) {
            return true;
        }

        // Karak: Regent bookkeeping: the Field and the Duel aggregate validate these, the turn log just records them
        if ($this === self::CURSED_ROOM || $this === self::DUEL || $this === self::DUEL_LOOT) {
            return true;
        }

        // Spells cost no move: the Portal, then a Dark Thorn, then a move is legal; only tile placement,
        // a fight and the turn-ending pickups block them
        if ($this === self::USE_SPELL) {
            return !\in_array($previousAction, [
                self::PICK_TILE,
                self::ROTATE_TILE,
                self::PLACE_TILE,
                self::FATEWEAVER,
                self::MAGIC_SWAP,
                self::FIGHT_MONSTER,
                self::PICK_UP_EQUIPMENT,
                self::UNLOCK_CHEST,
                self::HEAL_AT_FOUNTAIN,
            ], true);
        }

        // Oracle "Fateweaver" settles the token of the room that was just placed
        if ($this === self::FATEWEAVER) {
            return $previousAction === self::PLACE_TILE;
        }

        // Markul "Lightning Attack" costs all 4 moves and Hanna "Sprint" replaces the normal movement: both open the turn
        if ($this === self::LIGHTNING_ATTACK || $this === self::SPRINT) {
            return $previousAction === null;
        }

        // Markul's retreat after a Lightning Attack: bookkeeping, the Field checks that a retreat is pending
        if ($this === self::RETREAT) {
            return true;
        }

        // Lord Karak is revealed whenever the fifth Lord Karak room is placed: bookkeeping
        if ($this === self::LORD_KARAK) {
            return true;
        }

        // Valduk "Tireless": only right after a fight (the Field checks that it was won and the loot is left)
        if ($this === self::TIRELESS) {
            return $previousAction === self::FIGHT_MONSTER;
        }

        // Lady Lorraine "Listening": explores the tile that was just placed (after its Fateweaver choice, if any)
        if ($this === self::LISTEN) {
            return $previousAction === self::PLACE_TILE || $previousAction === self::FATEWEAVER;
        }

        // After a Lightning Attack the moves are spent: only the reward on the monster tile or ending the turn is left
        if ($previousAction === self::LIGHTNING_ATTACK) {
            return $this === self::PICK_ITEM || $this === self::END_TURN;
        }

        // After Tireless the loot stays on the tile: the turn goes on like after a move
        if ($previousAction === self::TIRELESS) {
            return $this !== self::PICK_ITEM;
        }

        // No previous action means it's the start of the turn
        if ($previousAction === null) {
            // Only movement actions are allowed at start of turn
            return $this === self::MOVE
                || $this === self::DISCOVER_TILE
                || $this === self::USE_TELEPORT
                || $this === self::PICK_TILE
                || $this === self::PICK_ITEM
                || $this === self::HEAL_AT_FOUNTAIN // Allow healing as first action (e.g., when placed on fountain)
                || $this === self::MAGIC_SWAP; // Warlock "Magic Swap": only at the beginning of the turn
        }

        // After a Magic Swap the moves are spent: only the reward on the new tile or ending the turn is left
        if ($previousAction === self::MAGIC_SWAP) {
            return $this === self::PICK_ITEM || $this === self::END_TURN;
        }

        // After combat, allow manual pickup, end turn, or a hero ability (Warrior "Reincarnation" after a lost fight)
        if ($previousAction === self::FIGHT_MONSTER) {
            return $this === self::PICK_ITEM || $this === self::END_TURN || $this === self::USE_HERO_ABILITY;
        }

        // After pick up, unlock, or heal, turn ends
        if (\in_array($previousAction, [
            self::PICK_UP_EQUIPMENT,
            self::UNLOCK_CHEST,
            self::HEAL_AT_FOUNTAIN,
            // self::PICK_ITEM, // Removed - turn ending handled manually, but in practice turn ends anyway
        ], true)) {
            return false;
        }

        // A placed tile is entered next (Listening and the Fateweaver choice are handled above): no other move, no new
        // tile, no pickup before the hero steps onto it (Movement checks the step goes onto the placed tile)
        if ($previousAction === self::PLACE_TILE || $previousAction === self::FATEWEAVER) {
            return $this === self::MOVE;
        }

        // After PICK_TILE, only PLACE_TILE and ROTATE_TILE are allowed
        if ($previousAction === self::PICK_TILE) {
            return $this === self::PLACE_TILE || $this === self::ROTATE_TILE;
        }

        // After ROTATE_TILE, allow more rotations or placing the tile
        if ($previousAction === self::ROTATE_TILE) {
            return $this === self::PLACE_TILE || $this === self::ROTATE_TILE;
        }

        // After movement actions, most actions are allowed
        if ($previousAction === self::MOVE || $previousAction === self::DISCOVER_TILE || $previousAction === self::USE_TELEPORT) {
            return true;
        }

        // After spell use or hero ability, most actions except those are allowed
        if ($previousAction === self::USE_SPELL || $previousAction === self::USE_HERO_ABILITY) {
            return $this !== self::USE_SPELL && $this !== self::USE_HERO_ABILITY;
        }

        return true;
    }
}
