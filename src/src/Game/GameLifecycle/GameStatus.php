<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

/**
 * @internal
 *
 * @psalm-internal App\Game\GameLifecycle
 */
enum GameStatus: string
{
    case LOBBY = 'lobby';
    case STARTED = 'started';
    case TURN_IN_PROGRESS = 'turn_in_progress';
    case FINISHED = 'finished';

    public function isPreparing(): bool
    {
        return match ($this) {
            self::LOBBY => true,
            default => false,
        };
    }

    public function isInProgress(): bool
    {
        return match ($this) {
            self::STARTED, self::TURN_IN_PROGRESS => true,
            default => false,
        };
    }

    public function isFinished(): bool
    {
        return match ($this) {
            self::FINISHED => true,
            default => false,
        };
    }
}
