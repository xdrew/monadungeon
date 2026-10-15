<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\Player\HeroSkills;

/**
 * Hero-skill and spell decisions of the virtual player. Stateless: every input is a plain value, so the rules can be
 * tested without the API client; SmartVirtualPlayer gathers the state and carries the decisions out.
 *
 * Positions are "x,y" strings.
 */
final readonly class HeroTactics
{
    /**
     * Valduk keeps healing up to 4 HP: Berserk gives +1 at 3-4 HP and he still absorbs a lost fight.
     */
    public const int VALDUK_HEAL_LIMIT = 4;

    /**
     * Sprint pays off only when the target is farther than the 4 plain moves and within the 8 sprint moves.
     */
    public const int SPRINT_MIN_DISTANCE = 5;

    public const int SPRINT_MAX_DISTANCE = 8;

    /**
     * HP at or below which the hero heads for a fountain (Portal of Healing, Magic Swap onto a fountain, Sprint).
     */
    public const int LOW_HP = 2;

    /**
     * What to do after a roll that did not win: Double Attack, then the cheapest spells (+ Sacrifice) that win.
     *
     * Spells are only spent when they turn the fight into a victory; a fight that cannot be won keeps them.
     * A fireball costs 1, a frost fist 2 (one frost fist is preferred to two fireballs), a Sacrifice 1.5 (HP is
     * worth more than a spell); the Wizard keeps his spells, so they cost him nothing.
     *
     * @param int $monsterHp the monster's strength (battleInfo.monster)
     * @param int $totalDamage dice + weapons + bonuses already counted (battleInfo.totalDamage)
     * @param list<array{itemId: string, type: string}> $consumables spells offered by the server
     *
     * @return array{reroll: bool, consumableIds: list<string>, sacrifice: bool, wins: bool, reason: string}
     */
    public static function afterRoll(
        int $monsterHp,
        int $totalDamage,
        bool $tieIsWin,
        bool $canReroll,
        bool $canSacrificeHp,
        int $hp,
        array $consumables,
        bool $keepsSpells = false,
    ): array {
        $need = $monsterHp - $totalDamage + ($tieIsWin ? 0 : 1);
        if ($need <= 0) {
            return ['reroll' => false, 'consumableIds' => [], 'sacrifice' => false, 'wins' => true, 'reason' => 'already a win'];
        }

        $fireballs = array_values(array_filter($consumables, static fn(array $spell): bool => ($spell['type'] ?? '') === 'fireball'));
        $frostFists = array_values(array_filter($consumables, static fn(array $spell): bool => ($spell['type'] ?? '') === 'frost_fist'));
        $sacrifice = $canSacrificeHp && $hp >= 2;

        $best = null;
        for ($f = 0; $f <= \count($fireballs); ++$f) {
            for ($g = 0; $g <= \count($frostFists); ++$g) {
                foreach ($sacrifice ? [false, true] : [false] as $s) {
                    $added = $f + 2 * $g + ($s ? 1 : 0);
                    if ($added < $need) {
                        continue;
                    }
                    $cost = ($keepsSpells ? 0.0 : $f + 2.0 * $g) + ($s ? 1.5 : 0.0);
                    $key = [$cost, $f + $g + ($s ? 1 : 0), $added];
                    if ($best === null || $key < $best['key']) {
                        $best = ['key' => $key, 'f' => $f, 'g' => $g, 's' => $s];
                    }
                }
            }
        }

        if ($best === null) {
            if ($canReroll) {
                return ['reroll' => true, 'consumableIds' => [], 'sacrifice' => false, 'wins' => false, 'reason' => "Double Attack: {$need} short, spells cannot close it"];
            }

            return ['reroll' => false, 'consumableIds' => [], 'sacrifice' => false, 'wins' => false, 'reason' => "{$need} short, keeping the spells"];
        }

        $ids = array_merge(
            array_map(static fn(array $spell): string => (string) $spell['itemId'], \array_slice($fireballs, 0, $best['f'])),
            array_map(static fn(array $spell): string => (string) $spell['itemId'], \array_slice($frostFists, 0, $best['g'])),
        );

        return [
            'reroll' => false,
            'consumableIds' => $ids,
            'sacrifice' => $best['s'],
            'wins' => true,
            'reason' => sprintf('%d short: %d fireball(s), %d frost fist(s)%s', $need, $best['f'], $best['g'], $best['s'] ? ', Sacrifice' : ''),
        ];
    }

    /**
     * Valduk "Tireless": leave loot the AI does not want and keep moving.
     */
    public static function useTireless(?HeroSkills $skills, bool $wantsLoot, int $movesLeft): bool
    {
        return $skills?->tireless() === true && !$wantsLoot && $movesLeft > 0;
    }

    /**
     * Valduk "Berserk": the heal limit to set, or null to leave it as it is (set once; never while cursed).
     */
    public static function healLimit(?HeroSkills $skills, ?int $current): ?int
    {
        if ($skills?->choosesHealing() !== true || $current !== null) {
            return null;
        }

        return self::VALDUK_HEAL_LIMIT;
    }

    /**
     * Fountain to go to (Reincarnation, retreat): the one nearest to the AI's target, else nearest to the hero.
     *
     * @param list<string> $fountains
     */
    public static function pickFountain(array $fountains, string $from, ?string $target = null): ?string
    {
        if ($fountains === []) {
            return null;
        }
        $anchor = $target ?? $from;
        usort($fountains, static fn(string $a, string $b): int => [self::distance($a, $anchor), self::distance($a, $from)]
            <=> [self::distance($b, $anchor), self::distance($b, $from)]);

        return $fountains[0];
    }

    /**
     * Portal of Healing on itself: low HP or cursed, not on a fountain already → the nearest fountain.
     *
     * @param list<string> $fountains
     */
    public static function portalTarget(bool $hasPortal, int $hp, bool $cursed, string $position, array $fountains): ?string
    {
        if (!$hasPortal || \in_array($position, $fountains, true) || ($hp > self::LOW_HP && !$cursed)) {
            return null;
        }

        return self::pickFountain($fountains, $position);
    }

    /**
     * Dark Thorn targets, one per thorn: a rival at 1 HP who would be stunned (not a reincarnating Warrior)
     * first, otherwise the rival with the most treasure; a stunned rival (0 HP) is never chosen.
     *
     * @param list<array{playerId: string, hp: int, treasure: int, reincarnates: bool}> $rivals
     *
     * @return list<string>
     */
    public static function darkThornTargets(int $thorns, array $rivals): array
    {
        $targets = [];
        for ($i = 0; $i < $thorns; ++$i) {
            $alive = array_values(array_filter($rivals, static fn(array $rival): bool => $rival['hp'] > 0));
            if ($alive === []) {
                break;
            }
            usort($alive, static fn(array $a, array $b): int => [
                $b['hp'] === 1 && !$b['reincarnates'], $b['treasure'], -$b['hp'],
            ] <=> [
                $a['hp'] === 1 && !$a['reincarnates'], $a['treasure'], -$a['hp'],
            ]);
            $chosen = $alive[0]['playerId'];
            $targets[] = $chosen;
            foreach ($rivals as $index => $rival) {
                if ($rival['playerId'] === $chosen) {
                    $rivals[$index]['hp'] = $rival['hp'] - 1;
                }
            }
        }

        return $targets;
    }

    /**
     * Warlock "Magic Swap" (all 4 moves): onto a rival standing on a fountain when the hero is low and no fountain
     * is in reach; otherwise onto a rival next to the dragon when the hero would fight it and is far from it;
     * otherwise, holding a key, onto a rival next to an unopened chest when every chest is far away.
     *
     * @param list<array{playerId: string, position: string}> $rivals
     * @param list<string> $fountains
     * @param list<string> $chests unopened chests, used only when the hero holds a key
     */
    public static function magicSwapTarget(
        ?HeroSkills $skills,
        int $hp,
        string $position,
        array $rivals,
        array $fountains,
        bool $fountainInReach,
        ?string $dragonPosition = null,
        bool $dragonWorthIt = false,
        bool $hasKey = false,
        array $chests = [],
    ): ?string {
        if ($skills?->canMagicSwap() !== true || $rivals === []) {
            return null;
        }

        if ($hp <= self::LOW_HP && !$fountainInReach && !\in_array($position, $fountains, true)) {
            foreach ($rivals as $rival) {
                if (\in_array($rival['position'], $fountains, true)) {
                    return $rival['playerId'];
                }
            }
        }

        if ($dragonPosition !== null && $dragonWorthIt && self::distance($position, $dragonPosition) > 4) {
            foreach ($rivals as $rival) {
                if (self::distance($rival['position'], $dragonPosition) === 1) {
                    return $rival['playerId'];
                }
            }
        }

        if ($hasKey && $chests !== [] && self::nearestDistance($position, $chests) > 4) {
            foreach ($rivals as $rival) {
                if (self::nearestDistance($rival['position'], $chests) === 1) {
                    return $rival['playerId'];
                }
            }
        }

        return null;
    }

    /**
     * Baron Markul "Lightning Attack" (all 4 moves): the most valuable monster he beats often enough; never at 1 HP,
     * where a lost fight knocks him out.
     *
     * @param list<array{position: string, winChance: float, value: int}> $monsters value 0 = not worth a fight
     */
    public static function lightningTarget(?HeroSkills $skills, array $monsters, float $minWinChance, int $hp = 5): ?string
    {
        if ($skills?->lightningAttack() !== true || $hp <= 1) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($monsters as $monster) {
            if ($monster['value'] <= 0 || $monster['winChance'] < $minWinChance) {
                continue;
            }
            $score = $monster['value'] * $monster['winChance'];
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $monster['position'];
            }
        }

        return $best;
    }

    /**
     * Hanna "Sprint": the target lies over discovered tiles farther than 4 and at most 8 moves away.
     */
    public static function shouldSprint(?HeroSkills $skills, ?int $distance): bool
    {
        return $skills?->sprints() === true && $distance !== null
            && $distance >= self::SPRINT_MIN_DISTANCE && $distance <= self::SPRINT_MAX_DISTANCE;
    }

    /**
     * Thief "Stealth": walk past a monster the hero would rather not fight.
     */
    public static function shouldSneak(?HeroSkills $skills, float $winChance, float $minWinChance): bool
    {
        return $skills?->canSneakPastMonsters() === true && $winChance < $minWinChance;
    }

    /**
     * Lady Lorraine "Listening": the new room's token is already known when she decides; she stays out of a room
     * whose monster she would rather not fight, or any monster at 1 HP (a lost fight would knock her out).
     * An empty room is simply entered.
     *
     * @param float|null $winChance null when the room holds no monster
     */
    public static function shouldListen(?HeroSkills $skills, int $hp, ?float $winChance, float $minWinChance): bool
    {
        if ($skills?->listens() !== true || $winChance === null) {
            return false;
        }

        return $hp <= 1 || $winChance < $minWinChance;
    }

    /**
     * Retreat after a Lightning Attack that was not won: the option nearest to a fountain.
     *
     * @param list<string> $options
     * @param list<string> $fountains
     */
    public static function pickRetreat(array $options, array $fountains): ?string
    {
        if ($options === []) {
            return null;
        }
        if ($fountains === []) {
            return $options[0];
        }
        usort($options, static fn(string $a, string $b): int => self::nearestDistance($a, $fountains) <=> self::nearestDistance($b, $fountains));

        return $options[0];
    }

    /**
     * Manhattan distance between two "x,y" positions.
     */
    public static function distance(string $a, string $b): int
    {
        [$ax, $ay] = array_map('intval', explode(',', $a));
        [$bx, $by] = array_map('intval', explode(',', $b));

        return abs($ax - $bx) + abs($ay - $by);
    }

    /**
     * @param list<string> $targets
     */
    private static function nearestDistance(string $from, array $targets): int
    {
        return min(array_map(static fn(string $target): int => self::distance($from, $target), $targets));
    }
}
