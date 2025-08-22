<?php

declare(strict_types=1);

namespace App\Game\Leaderboard;

use Telephantast\Message\Message;

/**
 * @psalm-immutable
 */
final readonly class GetLeaderboard implements Message
{
    public function __construct(
        public int $page = 1,
        public int $limit = 20,
        public string $sortBy = 'victories', // 'victories' or 'totalGames'
        public string $sortOrder = 'DESC', // 'ASC' or 'DESC'
        public ?string $currentPlayerWallet = null,
    ) {}
}