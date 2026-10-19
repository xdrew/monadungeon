<?php

declare(strict_types=1);

namespace App\Api\Leaderboard\Heroes;

/**
 * The heroes' table from per-hero counts: games, victories, win rate, sorted, ranked. A game without
 * a hero (the lobby's "no hero") is a row of its own, hero null.
 */
final class HeroStandings
{
    public const array SORTS = ['victories', 'games', 'win_rate'];

    /**
     * @param list<array{hero: string|null, games: int|string, victories: int|string}> $rows
     *
     * @return list<array{position: int, hero: string|null, games: int, victories: int, winRate: float}>
     */
    public static function build(array $rows, string $sortBy = 'victories', string $sortOrder = 'DESC'): array
    {
        $sortBy = \in_array($sortBy, self::SORTS, true) ? $sortBy : 'victories';
        $descending = strtoupper($sortOrder) !== 'ASC';
        $entries = array_map(static function (array $row): array {
            $games = (int) $row['games'];
            $victories = (int) $row['victories'];

            return [
                'hero' => $row['hero'] === null ? null : (string) $row['hero'],
                'games' => $games,
                'victories' => $victories,
                'winRate' => $games > 0 ? round($victories / $games * 100, 1) : 0.0,
            ];
        }, $rows);
        $key = ['victories' => 'victories', 'games' => 'games', 'win_rate' => 'winRate'][$sortBy];
        usort($entries, static function (array $a, array $b) use ($key, $descending): int {
            $order = $descending ? $b[$key] <=> $a[$key] : $a[$key] <=> $b[$key];

            return $order !== 0 ? $order : ($b['games'] <=> $a['games'] ?: strcmp((string) $a['hero'], (string) $b['hero']));
        });

        $ranked = [];
        foreach ($entries as $index => $entry) {
            $ranked[] = ['position' => $index + 1] + $entry;
        }

        return $ranked;
    }
}
