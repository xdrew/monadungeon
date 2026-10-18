<?php

declare(strict_types=1);

namespace App\Api\Game\AddPlayer;

/**
 * Who a new human seat is. In a game in one browser it is the caller's account; in a hot-seat game only the first
 * human seat is: the others are guests, named by the request (they keep no account and stay off the players' board).
 */
final readonly class SeatIdentity
{
    public const int MAX_NAME_LENGTH = 24;

    private function __construct(
        public ?string $externalId,
        public string $username,
        public bool $guest,
    ) {}

    /**
     * @param list<array{externalId: ?string, isAi: bool}> $seats the game's seats so far
     */
    public static function for(bool $hotseat, string $accountId, string $accountName, array $seats, ?string $requestedName): self
    {
        if (!$hotseat) {
            return new self($accountId, $accountName, false);
        }
        $accountSeated = false;
        $humans = 0;
        foreach ($seats as $seat) {
            if ($seat['isAi']) {
                continue;
            }
            ++$humans;
            if ($seat['externalId'] === $accountId) {
                $accountSeated = true;
            }
        }
        if (!$accountSeated) {
            return new self($accountId, $accountName, false);
        }

        $name = trim((string) $requestedName);
        if ($name === '') {
            $name = sprintf('Adventurer %d', $humans + 1);
        }

        return new self(null, mb_substr($name, 0, self::MAX_NAME_LENGTH), true);
    }
}
