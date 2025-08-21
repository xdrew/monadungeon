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
        ], true);
    }

    public function isIncreasesActionCounter(): bool
    {
        return \in_array($this, [
            self::MOVE,
            self::USE_TELEPORT,
            // Note: PICK_TILE, PLACE_TILE, ROTATE_TILE do NOT increase action counter as they are part of exploration
            // Note: Combat and item actions don't count - they end the turn instead
        ], true);
    }

    public function isAllowedAfter(?self $previousAction): bool
    {
        // No previous action means it's the start of the turn
        if ($previousAction === null) {
            // Only movement actions are allowed at start of turn
            return $this === self::MOVE
                || $this === self::DISCOVER_TILE
                || $this === self::USE_TELEPORT
                || $this === self::PICK_TILE
                || $this === self::PICK_ITEM
                || $this === self::HEAL_AT_FOUNTAIN; // Allow healing as first action (e.g., when placed on fountain)
        }

        // After combat, allow manual pickup or end turn (for manual pickup system)
        if ($previousAction === self::FIGHT_MONSTER) {
            return $this === self::PICK_ITEM || $this === self::END_TURN;
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
