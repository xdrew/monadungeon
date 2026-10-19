<?php

declare(strict_types=1);

namespace App\Api\Leaderboard\Heroes;

final readonly class Response
{
    /**
     * @param list<array{position: int, hero: string|null, games: int, victories: int, winRate: float}> $entries
     * @param array{sortBy: string, sortOrder: string} $sorting
     */
    public function __construct(
        public array $entries,
        public array $sorting,
    ) {}
}
