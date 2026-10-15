<?php

declare(strict_types=1);

namespace App\Tests\Game\AI;

use App\Game\AI\BattleOdds;
use App\Game\Player\HeroSkills;
use App\Game\Player\HeroType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exact fight odds of the virtual player with the hero skills the server applies.
 */
#[CoversClass(BattleOdds::class)]
final class BattleOddsTest extends TestCase
{
    private const float DELTA = 1e-9;

    #[Test]
    public function plainDiceAgainstSeven(): void
    {
        $chances = BattleOdds::chances(monsterHp: 7, itemDamage: 0, skills: null, hp: 5);

        self::assertEqualsWithDelta(15 / 36, $chances['win'], self::DELTA);
        self::assertEqualsWithDelta(6 / 36, $chances['draw'], self::DELTA);
        self::assertEqualsWithDelta(15 / 36, $chances['loss'], self::DELTA);
    }

    #[Test]
    public function weaponsAddToTheRoll(): void
    {
        self::assertEqualsWithDelta(15 / 36, BattleOdds::winChance(10, 3, null, 5), self::DELTA);
    }

    #[Test]
    public function combatTrainingRerollsOnes(): void
    {
        self::assertEqualsWithDelta(15 / 25, BattleOdds::winChance(7, 0, $this->skills(HeroType::SWORDSMAN), 5), self::DELTA);
        self::assertEqualsWithDelta(8.0, BattleOdds::expectedRoll($this->skills(HeroType::SWORDSMAN)), self::DELTA);
    }

    #[Test]
    public function bearAttackTurnsOnesIntoSixes(): void
    {
        self::assertEqualsWithDelta(13 / 18, BattleOdds::winChance(7, 0, $this->skills(HeroType::RANGER), 5), self::DELTA);
        self::assertEqualsWithDelta(26 / 3, BattleOdds::expectedRoll($this->skills(HeroType::RANGER)), self::DELTA);
    }

    #[Test]
    public function backstabWinsTies(): void
    {
        $chances = BattleOdds::chances(7, 0, $this->skills(HeroType::THIEF), 5);

        self::assertEqualsWithDelta(21 / 36, $chances['win'], self::DELTA);
        self::assertEqualsWithDelta(0.0, $chances['draw'], self::DELTA);
    }

    #[Test]
    public function doubleAttackRerollsAFightThatIsNotWon(): void
    {
        $chances = BattleOdds::chances(7, 0, $this->skills(HeroType::WARRIOR), 5);

        self::assertEqualsWithDelta(95 / 144, $chances['win'], self::DELTA);
        self::assertEqualsWithDelta(21 / 36 * 6 / 36, $chances['draw'], self::DELTA);
        self::assertEqualsWithDelta(1.0, $chances['win'] + $chances['draw'] + $chances['loss'], self::DELTA);
    }

    #[Test]
    public function berserkDependsOnHp(): void
    {
        $valduk = $this->skills(HeroType::BARBARIAN);

        self::assertEqualsWithDelta(BattleOdds::winChance(7, 0, null, 5), BattleOdds::winChance(7, 0, $valduk, 5), self::DELTA);
        self::assertEqualsWithDelta(15 / 36, BattleOdds::winChance(8, 0, $valduk, 3), self::DELTA, '+1 at 3 HP');
        self::assertEqualsWithDelta(15 / 36, BattleOdds::winChance(9, 0, $valduk, 1), self::DELTA, '+2 at 1 HP');
    }

    #[Test]
    public function farseeingOnlyOnTheFirstMove(): void
    {
        $oracle = $this->skills(HeroType::ORACLE);

        self::assertEqualsWithDelta(15 / 36, BattleOdds::winChance(8, 0, $oracle, 5, firstMove: true), self::DELTA);
        self::assertEqualsWithDelta(10 / 36, BattleOdds::winChance(8, 0, $oracle, 5, firstMove: false), self::DELTA);
    }

    #[Test]
    public function spellsAddFireballOneAndFrostFistTwo(): void
    {
        self::assertEqualsWithDelta(15 / 36, BattleOdds::winChance(10, 0, null, 5, fireballs: 1, frostFists: 1), self::DELTA);
    }

    #[Test]
    public function sacrificeNeverPaysTheLastHp(): void
    {
        $warlock = $this->skills(HeroType::WARLOCK);

        self::assertEqualsWithDelta(15 / 36, BattleOdds::winChance(8, 0, $warlock, 2), self::DELTA);
        self::assertEqualsWithDelta(10 / 36, BattleOdds::winChance(8, 0, $warlock, 1), self::DELTA);
    }

    #[Test]
    public function lordKarakCombinesTheSkillsHeTookOver(): void
    {
        $lordKarak = new HeroSkills([HeroType::RANGER, HeroType::THIEF]);

        // Bear Attack dice, ties won: total ≥ 7 with the 1 → 6 die
        $bear = [2 => 1 / 6, 3 => 1 / 6, 4 => 1 / 6, 5 => 1 / 6, 6 => 2 / 6];
        $expected = 0.0;
        foreach ($bear as $a => $pa) {
            foreach ($bear as $b => $pb) {
                $expected += $a + $b >= 7 ? $pa * $pb : 0.0;
            }
        }

        self::assertEqualsWithDelta($expected, BattleOdds::winChance(7, 0, $lordKarak, 5), self::DELTA);
    }

    #[Test]
    public function aCursedHeroFightsWithPlainDice(): void
    {
        self::assertEqualsWithDelta(15 / 36, BattleOdds::winChance(7, 0, null, 5), self::DELTA);
        self::assertEqualsWithDelta(7.0, BattleOdds::expectedRoll(null), self::DELTA);
    }

    #[Test]
    public function maxTotalCountsEveryBonus(): void
    {
        self::assertSame(12, BattleOdds::maxTotal(0, null, 5));
        self::assertSame(12 + 3 + 2 + 1 + 4, BattleOdds::maxTotal(3, $this->skills(HeroType::BARBARIAN), 1, fireballs: 1, frostFists: 2));
        self::assertSame(13, BattleOdds::maxTotal(0, $this->skills(HeroType::WARLOCK), 5));
    }

    private function skills(HeroType $hero): HeroSkills
    {
        return HeroSkills::of($hero);
    }
}
