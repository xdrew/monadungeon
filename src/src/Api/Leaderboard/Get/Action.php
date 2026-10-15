<?php

declare(strict_types=1);

namespace App\Api\Leaderboard\Get;

use App\Api\Error;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class Action
{
    public function __construct(
        private Connection $connection,
    ) {}

    #[Route('/leaderboard', methods: ['GET'])]
    public function __invoke(Request $request): Response|Error
    {
        try {
            // Get query parameters
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
            $sortBy = $request->query->get('sortBy', 'victories');
            $sortOrder = strtoupper($request->query->get('sortOrder', 'DESC'));

            // Validate sort parameters
            if (!\in_array($sortBy, ['victories', 'total_games'], true)) {
                $sortBy = 'victories';
            }
            if (!\in_array($sortOrder, ['ASC', 'DESC'], true)) {
                $sortOrder = 'DESC';
            }

            // Calculate offset
            $offset = ($page - 1) * $limit;

            // Get total count
            $countSql = <<<'SQL'
                    SELECT COUNT(*) as total
                    FROM game.leaderboard
                SQL;

            $totalCount = (int) $this->connection->fetchOne($countSql);

            // Get leaderboard entries
            $entriesSql = <<<SQL
                    SELECT 
                        id,
                        username,
                        external_id,
                        victories,
                        total_games,
                        created_at,
                        updated_at
                    FROM game.leaderboard
                    ORDER BY {$sortBy} {$sortOrder}, username ASC
                    LIMIT :limit OFFSET :offset
                SQL;

            $entries = $this->connection->fetchAllAssociative($entriesSql, [
                'limit' => $limit,
                'offset' => $offset,
            ]);

            $formattedEntries = [];
            $position = $offset + 1;

            foreach ($entries as $entry) {
                $formattedEntries[] = $this->formatEntry($entry, $position++);
            }

            return new Response(
                entries: $formattedEntries,
                pagination: [
                    'page' => $page,
                    'limit' => $limit,
                    'totalCount' => $totalCount,
                    'totalPages' => (int) ceil($totalCount / $limit),
                ],
                sorting: [
                    'sortBy' => $sortBy,
                    'sortOrder' => $sortOrder,
                ],
            );
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Failed to get leaderboard: ' . $e->getMessage());
        }
    }

    private function formatEntry(array $entry, int $position): array
    {
        $victories = (int) $entry['victories'];
        $totalGames = (int) $entry['total_games'];

        return [
            'position' => $position,
            'username' => $entry['username'],
            'victories' => $victories,
            'totalGames' => $totalGames,
            'winRate' => $totalGames > 0
                ? round(($victories / $totalGames) * 100, 1)
                : 0,
        ];
    }
}
