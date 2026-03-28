<?php

declare(strict_types=1);

namespace App\Game\Turn\Repository;

use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Connection;

final readonly class GameTurnRepository
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @return list<array<array-key, mixed>>
     */
    public function getForApi(Uuid $gameId, int $limit = 2): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                select turn_id, actions, player_id, turn_number, start_time, end_time, pending_item_pickup
                from game_turn.game_turn
                where game_id = :game_id
                order by turn_number desc
                limit :limit
                SQL,
            ['game_id' => $gameId->toString(), 'limit' => $limit],
        );

        return array_reverse($rows);
    }
}
