<?php

declare(strict_types=1);

namespace App\Tests\Game\Player;

use App\Game\Player\HeroSkills;
use App\Game\Player\HeroType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The skills that apply right now: one hero, or the merged set of Lord Karak (Karak: Regent variant).
 */
#[CoversClass(HeroSkills::class)]
final class HeroSkillsTest extends TestCase
{
    /**
     * @return iterable<string, array{HeroType}>
     */
    public static function heroes(): iterable
    {
        foreach (HeroType::cases() as $hero) {
            yield $hero->value => [$hero];
        }
    }

    #[Test]
    #[DataProvider('heroes')]
    public function aSingleHeroMatchesHisCard(HeroType $hero): void
    {
        $skills = HeroSkills::of($hero);

        self::assertSame($hero->flags(), $skills->flags());
        self::assertSame($hero->skills(), $skills->skills());
        self::assertSame($hero->label(), $skills->label());
        foreach ([5, 4, 3, 2, 1, 0] as $hp) {
            self::assertSame($hero->berserkBonus($hp), $skills->berserkBonus($hp));
        }
    }

    #[Test]
    public function lordKarakHasEverySkillOfHisHeroes(): void
    {
        $skills = new HeroSkills([HeroType::RANGER, HeroType::BARBARIAN, HeroType::THIEF]);

        self::assertSame('Lord Karak', $skills->label());
        self::assertTrue($skills->bearAttack());
        self::assertTrue($skills->listens());
        self::assertTrue($skills->berserk());
        self::assertTrue($skills->tireless());
        self::assertTrue($skills->winsTies());
        self::assertTrue($skills->canSneakPastMonsters());
        self::assertFalse($skills->canRerollBattle());
        self::assertFalse($skills->sprints());
        self::assertSame(2, $skills->berserkBonus(2));
        self::assertSame(0, $skills->berserkBonus(5));

        $flags = $skills->flags();
        self::assertSame(array_keys(HeroType::WARRIOR->flags()), array_keys($flags));
        self::assertTrue($flags['bearAttack']);
        self::assertTrue($flags['berserk']);
        self::assertTrue($flags['winsTies']);
        self::assertFalse($flags['lightningAttack']);

        $names = array_column($skills->skills(), 'name');
        self::assertSame(['Bear Attack', 'Listening', 'Berserk', 'Tireless', 'Backstab', 'Stealth'], $names);
        self::assertSame(['bear_attack', 'listening', 'berserk', 'tireless', 'backstab', 'stealth'], array_column($skills->skills(), 'key'));
    }

    #[Test]
    public function anEmptySetHasNoSkills(): void
    {
        $skills = new HeroSkills([]);

        self::assertSame('Lord Karak', $skills->label());
        self::assertNotContains(true, $skills->flags());
        self::assertSame([], $skills->skills());
        self::assertSame(0, $skills->berserkBonus(1));
    }
}
