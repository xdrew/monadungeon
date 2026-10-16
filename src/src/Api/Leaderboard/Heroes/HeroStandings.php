<?php

declare(strict_types=1);

namespace App\Api\Leaderboard\Heroes;

/**
 * The heroes' table from per-hero counts: games, victories, win rate, sorted, ranked.
 */
final class HeroStandings
{
    public const array SORTS = ['victories', 'games', 'win_rate'];

    /**
     * @param list<array{hero: string, games: int|string, victories: int|string}> $rows
     *
     * @return list<array{position: int, hero: string, games: int, victories: int, winRate: float}>
     */
    public static function build(array $rows, string $sortBy = 'victories', string $sortOrder = 'DESC'): array
    {
        $sortBy = \in_array($sortBy, self::SORTS, true) ? $sortBy : 'victories';
        $descending = strtoupper($sortOrder) !== 'ASC';
        $entries = array_map(static function (array $row): array {
            $games = (int) $row['games'];
            $victories = (int) $row['victories'];

            return [
                'hero' => (string) $row['hero'],
                'games' => $games,
                'victories' => $victories,
                'winRate' => $games > 0 ? round($victories / $games * 100, 1) : 0.0,
            ];
        }, $rows);
        $key = ['victories' => 'victories', 'games' => 'games', 'win_rate' => 'winRate'][$sortBy];
        usort($entries, static function (array $a, array $b) use ($key, $descending): int {
            $order = $descending ? $b[$key] <=> $a[$key] : $a[$key] <=> $b[$key];

            return $order !== 0 ? $order : ($b['games'] <=> $a['games'] ?: strcmp($a['hero'], $b['hero']));
        });

        $ranked = [];
        foreach ($entries as $index => $entry) {
            $ranked[] = ['position' => $index + 1] + $entry;
        }

        return $ranked;
    }
}
