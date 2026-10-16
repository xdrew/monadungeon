<?php

declare(strict_types=1);

namespace App\Tests\Api\Leaderboard;

use App\Api\Leaderboard\Heroes\HeroStandings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeroStandings::class)]
final class HeroStandingsTest extends TestCase
{
    private const array ROWS = [
        ['hero' => 'thief', 'games' => '4', 'victories' => '1'],
        ['hero' => 'warrior', 'games' => '5', 'victories' => '3'],
        ['hero' => 'wizard', 'games' => '2', 'victories' => '2'],
    ];

    #[Test]
    public function byVictoriesWithWinRates(): void
    {
        $table = HeroStandings::build(self::ROWS);

        self::assertSame(['warrior', 'wizard', 'thief'], array_column($table, 'hero'));
        self::assertSame([1, 2, 3], array_column($table, 'position'));
        self::assertSame(60.0, $table[0]['winRate']);
        self::assertSame(25.0, $table[2]['winRate']);
    }

    #[Test]
    public function byWinRateAndByGames(): void
    {
        self::assertSame(['wizard', 'warrior', 'thief'], array_column(HeroStandings::build(self::ROWS, 'win_rate'), 'hero'));
        self::assertSame(['wizard', 'thief', 'warrior'], array_column(HeroStandings::build(self::ROWS, 'games', 'ASC'), 'hero'));
    }

    #[Test]
    public function anUnknownSortFallsBackToVictories(): void
    {
        self::assertSame('warrior', HeroStandings::build(self::ROWS, 'drop table')[0]['hero']);
    }
}
