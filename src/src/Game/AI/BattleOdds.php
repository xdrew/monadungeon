<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\Player\HeroSkills;

/**
 * Exact odds of a monster fight for the virtual player, with the hero skills the server applies
 * (Battle::rollDice, Battle::resolveResult, Battle::finalizeBattle):
 *  - Combat Training (rerollsOnes): a 1 is rerolled until 2-6 → a die is uniform on 2-6;
 *  - Bear Attack (bearAttack): a 1 counts as 6;
 *  - Backstab (winsTies): a tie is a victory;
 *  - Double Attack (canRerollBattle): a roll that does not win is rerolled once, the second roll is final;
 *  - Berserk, Farseeing: flat bonuses; Sacrifice: +1 bought with 1 HP (only while HP ≥ 2, the AI never pays its last HP);
 *  - spells after the roll: fireball +1, frost fist +2 each.
 * A cursed hero (skills null) fights with plain 2d6.
 */
final readonly class BattleOdds
{
    /**
     * @param int $monsterHp the monster's strength: the fight is won when the total exceeds it
     * @param int $itemDamage weapons in hand (Player::calculateItemDamage: Sword Master, slot daggers included)
     * @param int $hp the hero's HP when the dice are rolled (Berserk, Sacrifice)
     * @param int $fireballs fireballs the AI is ready to spend
     * @param int $frostFists frost fists the AI is ready to spend
     * @param bool $firstMove no move of the turn spent yet (Oracle "Farseeing")
     *
     * @return array{win: float, draw: float, loss: float}
     */
    public static function chances(
        int $monsterHp,
        int $itemDamage,
        ?HeroSkills $skills,
        int $hp,
        int $fireballs = 0,
        int $frostFists = 0,
        bool $firstMove = false,
    ): array {
        $flat = $itemDamage + self::skillBonus($skills, $hp, $firstMove) + $fireballs + 2 * $frostFists
            + (self::canSacrifice($skills, $hp) ? 1 : 0);
        $winsTies = $skills?->winsTies() === true;

        $win = 0.0;
        $draw = 0.0;
        foreach (self::rollDistribution($skills) as $roll => $probability) {
            $total = $roll + $flat;
            if ($total > $monsterHp || ($winsTies && $total === $monsterHp)) {
                $win += $probability;
            } elseif ($total === $monsterHp) {
                $draw += $probability;
            }
        }
        $loss = max(0.0, 1.0 - $win - $draw);

        // Double Attack: any roll that does not win is rerolled once, the second result stands
        if ($skills?->canRerollBattle() === true) {
            $notWin = 1.0 - $win;

            return ['win' => $win + $notWin * $win, 'draw' => $notWin * $draw, 'loss' => $notWin * $loss];
        }

        return ['win' => $win, 'draw' => $draw, 'loss' => $loss];
    }

    /**
     * Win probability only (see chances()).
     */
    public static function winChance(
        int $monsterHp,
        int $itemDamage,
        ?HeroSkills $skills,
        int $hp,
        int $fireballs = 0,
        int $frostFists = 0,
        bool $firstMove = false,
    ): float {
        return self::chances($monsterHp, $itemDamage, $skills, $hp, $fireballs, $frostFists, $firstMove)['win'];
    }

    /**
     * Highest total the hero can reach: 12 on the dice plus every bonus.
     */
    public static function maxTotal(int $itemDamage, ?HeroSkills $skills, int $hp, int $fireballs = 0, int $frostFists = 0): int
    {
        return 12 + $itemDamage + self::skillBonus($skills, $hp, false) + $fireballs + 2 * $frostFists
            + (self::canSacrifice($skills, $hp) ? 1 : 0);
    }

    /**
     * Average of the two dice with the dice skills (7 plain, 8 with Combat Training, ≈8.7 with Bear Attack).
     */
    public static function expectedRoll(?HeroSkills $skills): float
    {
        $expected = 0.0;
        foreach (self::rollDistribution($skills) as $roll => $probability) {
            $expected += $roll * $probability;
        }

        return $expected;
    }

    /**
     * Flat bonuses that need no decision: Berserk for the current HP, Farseeing on the first move.
     */
    public static function skillBonus(?HeroSkills $skills, int $hp, bool $firstMove): int
    {
        if ($skills === null) {
            return 0;
        }

        return ($skills->berserk() ? $skills->berserkBonus($hp) : 0)
            + ($firstMove && $skills->fightsBetterOnFirstMove() ? 1 : 0);
    }

    /**
     * Warlock "Sacrifice" the AI is willing to use: never with the last HP.
     */
    public static function canSacrifice(?HeroSkills $skills, int $hp): bool
    {
        return $skills?->sacrificesHp() === true && $hp >= 2;
    }

    /**
     * Distribution of the sum of two dice.
     *
     * @return array<int, float> sum => probability
     */
    public static function rollDistribution(?HeroSkills $skills): array
    {
        $die = self::dieDistribution($skills);
        $sums = [];
        foreach ($die as $a => $pa) {
            foreach ($die as $b => $pb) {
                $sums[$a + $b] = ($sums[$a + $b] ?? 0.0) + $pa * $pb;
            }
        }
        ksort($sums);

        return $sums;
    }

    /**
     * One die. Combat Training rerolls a 1 before Bear Attack could turn it into a 6 (Battle::rollDice order).
     *
     * @return array<int, float> face => probability
     */
    private static function dieDistribution(?HeroSkills $skills): array
    {
        if ($skills?->rerollsOnes() === true) {
            return [2 => 0.2, 3 => 0.2, 4 => 0.2, 5 => 0.2, 6 => 0.2];
        }
        if ($skills?->bearAttack() === true) {
            return [2 => 1 / 6, 3 => 1 / 6, 4 => 1 / 6, 5 => 1 / 6, 6 => 2 / 6];
        }

        return [1 => 1 / 6, 2 => 1 / 6, 3 => 1 / 6, 4 => 1 / 6, 5 => 1 / 6, 6 => 1 / 6];
    }
}
