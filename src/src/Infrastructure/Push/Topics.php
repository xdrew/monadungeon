<?php

declare(strict_types=1);

namespace App\Infrastructure\Push;

/**
 * Push topics. Subscribers are anonymous: a game topic is as secret as the game id, and payloads carry no secrets.
 */
final class Topics
{
    public const string TABLES = 'karak/tables';

    public static function game(string $gameId): string
    {
        return 'karak/game/' . $gameId;
    }
}
