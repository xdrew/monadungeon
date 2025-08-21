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
    public function getForApi(Uuid $gameId): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                select turn_id, actions, player_id, turn_number, start_time, end_time
                from game_turn.game_turn
                where game_id = :game_id
                order by turn_number
                SQL,
            ['game_id' => $gameId->toString()],
        );
    }
}
