<?php

declare(strict_types=1);

namespace App\Api\Security;

/**
 * Who may act for a seat. A human seat with an owner (Player.externalId = account id) only for that account;
 * an AI seat, in a game where some human seats have owners, only for one of those owners (the AI's turn is
 * started from a player's browser); seats nobody owns (old games, test-mode setups) for anyone.
 */
final class SeatGuard
{
    public const string ALLOWED = 'allowed';
    public const string NOT_YOURS = 'seat.not_yours';
    public const string NOT_AI = 'seat.not_ai';

    /**
     * @param array{isAi: bool, externalId: ?string} $seat the seat named by the request
     * @param list<string> $owners the owners of the human seats of that game
     * @param bool $aiTurn the request asks the AI to play the seat
     */
    public static function decide(array $seat, array $owners, ?string $callerId, bool $aiTurn): string
    {
        if ($aiTurn && !$seat['isAi']) {
            return self::NOT_AI;
        }
        if ($seat['isAi']) {
            if ($owners === []) {
                return self::ALLOWED;
            }

            return $callerId !== null && \in_array($callerId, $owners, true) ? self::ALLOWED : self::NOT_YOURS;
        }
        if ($seat['externalId'] === null || $seat['externalId'] === '') {
            return self::ALLOWED;
        }

        return $callerId === $seat['externalId'] ? self::ALLOWED : self::NOT_YOURS;
    }
}
