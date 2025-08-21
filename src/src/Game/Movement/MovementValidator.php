<?php

declare(strict_types=1);

namespace App\Game\Movement;

use App\Game\Field\FieldPlace;
use App\Game\Movement\Exception\CannotMoveAfterBattleException;
use App\Game\Movement\Exception\InvalidMovementException;
use App\Game\Movement\Exception\NotYourTurnException;
use App\Game\Movement\Exception\PlayerStunnedCanOnlyMoveToMonstersException;
use App\Infrastructure\Uuid\Uuid;

final readonly class MovementValidator
{
    /**
     * Validate if a player can move during their turn.
     *
     * @throws NotYourTurnException
     * @throws CannotMoveAfterBattleException
     * @throws InvalidMovementException
     * @throws PlayerStunnedCanOnlyMoveToMonstersException
     */
    public function validateMovement(
        Uuid $playerId,
        Uuid $currentPlayerId,
        FieldPlace $fromPosition,
        FieldPlace $toPosition,
        FieldPlace $actualPlayerPosition,
        bool $hasMovedAfterBattle,
        bool $canTransition,
        int $playerHp,
        bool $destinationHasMonster,
    ): void {
        // Check if it's the player's turn
        if (!$playerId->equals($currentPlayerId)) {
            throw new NotYourTurnException();
        }

        // Check if player has already moved after battle
        if ($hasMovedAfterBattle) {
            throw new CannotMoveAfterBattleException();
        }

        // Verify the from position matches player's actual position
        if (!$fromPosition->equals($actualPlayerPosition)) {
            throw new InvalidMovementException(
                sprintf(
                    'Player is not at the specified from position. Expected: %s, Actual: %s',
                    $fromPosition->toString(),
                    $actualPlayerPosition->toString(),
                ),
            );
        }

        // Check if movement is valid according to transitions
        if (!$canTransition) {
            throw new InvalidMovementException(
                sprintf(
                    'Cannot move from %s to %s - no valid transition',
                    $fromPosition->toString(),
                    $toPosition->toString(),
                ),
            );
        }

        // Special validation for stunned players
        if ($playerHp === 0 && !$destinationHasMonster) {
            throw new PlayerStunnedCanOnlyMoveToMonstersException();
        }
    }
}
