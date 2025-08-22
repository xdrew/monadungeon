<?php

declare(strict_types=1);

namespace App\Api\Leaderboard\Get;

use App\Api\Error;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class Action
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
            $currentPlayerWallet = $request->query->get('currentPlayerWallet');
            $currentPlayerUsername = $request->query->get('currentPlayerUsername');

            // Validate sort parameters
            if (!in_array($sortBy, ['victories', 'total_games'], true)) {
                $sortBy = 'victories';
            }
            if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
                $sortOrder = 'DESC';
            }

            // Calculate offset
            $offset = ($page - 1) * $limit;

            // Get total count
            $countSql = <<<SQL
                SELECT COUNT(*) as total
                FROM game.leaderboard
            SQL;
            
            $totalCount = (int) $this->connection->fetchOne($countSql);

            // Get leaderboard entries
            $entriesSql = <<<SQL
                SELECT 
                    id,
                    username,
                    wallet_address,
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

            // Find current player if wallet AND username provided (must be logged in)
            $currentPlayerEntry = null;
            $currentPlayerPosition = null;

            if ($currentPlayerWallet && $currentPlayerUsername) {
                // Get current player's data
                $playerSql = <<<SQL
                    SELECT 
                        id,
                        username,
                        wallet_address,
                        external_id,
                        victories,
                        total_games
                    FROM game.leaderboard
                    WHERE wallet_address = :wallet
                SQL;

                $currentPlayerData = $this->connection->fetchAssociative($playerSql, [
                    'wallet' => $currentPlayerWallet,
                ]);

                if ($currentPlayerData) {
                    // Verify username matches exactly to prevent showing wrong player as "You"
                    if ($currentPlayerData['username'] !== $currentPlayerUsername) {
                        $currentPlayerData = null;
                        $currentPlayerEntry = null;
                    } else {
                        // Calculate player's position
                        $positionSql = <<<SQL
                            SELECT COUNT(*) + 1 as position
                            FROM game.leaderboard
                            WHERE 
                                ({$sortBy} > :player_value)
                                OR ({$sortBy} = :player_value AND username < :player_username)
                        SQL;

                        if ($sortOrder === 'ASC') {
                            $positionSql = <<<SQL
                                SELECT COUNT(*) + 1 as position
                                FROM game.leaderboard
                                WHERE 
                                    ({$sortBy} < :player_value)
                                    OR ({$sortBy} = :player_value AND username < :player_username)
                            SQL;
                        }

                        $currentPlayerPosition = (int) $this->connection->fetchOne($positionSql, [
                            'player_value' => $currentPlayerData[$sortBy],
                            'player_username' => $currentPlayerData['username'],
                        ]);

                        $currentPlayerEntry = $currentPlayerData;
                    }
                }
            }

            // Format entries
            $formattedEntries = [];
            $position = $offset + 1;

            foreach ($entries as $entry) {
                // Only mark as current player if wallet matches AND username matches (for logged-in users)
                $isCurrentPlayer = false;
                if ($currentPlayerWallet && $entry['wallet_address'] === $currentPlayerWallet) {
                    if (!$currentPlayerUsername || $entry['username'] === $currentPlayerUsername) {
                        $isCurrentPlayer = true;
                    }
                }
                $formattedEntries[] = $this->formatEntry($entry, $position++, $isCurrentPlayer);
            }

            // Prepare current player info if available
            $currentPlayerInfo = null;
            if ($currentPlayerEntry && $currentPlayerPosition !== null) {
                $isOnCurrentPage = $currentPlayerPosition > $offset && $currentPlayerPosition <= ($offset + $limit);
                
                $currentPlayerInfo = [
                    'position' => $currentPlayerPosition,
                    'isOnCurrentPage' => $isOnCurrentPage,
                    'entry' => $isOnCurrentPage ? null : $this->formatEntry($currentPlayerEntry, $currentPlayerPosition, true),
                ];
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
                currentPlayer: $currentPlayerInfo,
            );
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Failed to get leaderboard: ' . $e->getMessage());
        }
    }

    private function formatEntry(array $entry, int $position, bool $isCurrentPlayer = false): array
    {
        $walletAddress = $entry['wallet_address'] ?? null;
        $username = $entry['username'];
        
        // Mask wallet address if no username (show first 6 and last 4 characters)
        $displayName = $username;
        if (empty($username) && $walletAddress) {
            $displayName = $this->maskWalletAddress($walletAddress);
        }
        
        $victories = (int) $entry['victories'];
        $totalGames = (int) $entry['total_games'];
        
        return [
            'position' => $position,
            'username' => $displayName,
            'walletAddress' => $walletAddress,
            'victories' => $victories,
            'totalGames' => $totalGames,
            'winRate' => $totalGames > 0 
                ? round(($victories / $totalGames) * 100, 1) 
                : 0,
            'isCurrentPlayer' => $isCurrentPlayer,
        ];
    }

    private function maskWalletAddress(string $wallet): string
    {
        if (strlen($wallet) <= 10) {
            return $wallet;
        }
        
        return substr($wallet, 0, 6) . '...' . substr($wallet, -4);
    }
}