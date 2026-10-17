<?php

declare(strict_types=1);

namespace App\Api\Leaderboard\Heroes;

use App\Api\Error;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Hall of fame by heroes: every finished game's heroes (the AI's too), how often each played and won.
 */
final readonly class Action
{
    public function __construct(
        private Connection $connection,
    ) {}

    #[Route('/leaderboard/heroes', methods: ['GET'])]
    public function __invoke(Request $request): Response|Error
    {
        $sortBy = (string) $request->query->get('sortBy', 'victories');
        $sortOrder = strtoupper((string) $request->query->get('sortOrder', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        try {
            /** @var list<array{hero: string, games: int|string, victories: int|string}> $rows */
            $rows = $this->connection->fetchAllAssociative(<<<'SQL'
                    SELECT p.hero AS hero,
                           COUNT(*) AS games,
                           -- every top score wins (equal top scores share the victory); games without stored
                           -- scores (an empty leaderboard) fall back to winner_id. CASE keeps jsonb_each_text away
                           -- from a leaderboard that is not an object.
                           SUM(CASE
                                   WHEN jsonb_typeof(g.leaderboard) = 'object' AND g.leaderboard <> '{}'::jsonb THEN
                                       CASE WHEN (g.leaderboard ->> p.player_id::text)::numeric = (
                                           SELECT MAX(s.value::numeric) FROM jsonb_each_text(
                                               CASE WHEN jsonb_typeof(g.leaderboard) = 'object' THEN g.leaderboard ELSE '{}'::jsonb END
                                           ) s
                                       ) THEN 1 ELSE 0 END
                                   WHEN g.winner_id = p.player_id THEN 1
                                   ELSE 0
                               END) AS victories
                    FROM player.player p
                    JOIN game.game g ON g.game_id = p.game_id
                    WHERE g.status = 'finished' AND g.end_reason IS NULL AND p.hero IS NOT NULL
                    GROUP BY p.hero
                SQL);
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Failed to get the heroes leaderboard: ' . $e->getMessage());
        }

        return new Response(
            entries: HeroStandings::build($rows, $sortBy, $sortOrder),
            sorting: ['sortBy' => \in_array($sortBy, HeroStandings::SORTS, true) ? $sortBy : 'victories', 'sortOrder' => $sortOrder],
        );
    }
}
