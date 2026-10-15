<?php

declare(strict_types=1);

namespace App\Tests\Game\AI;

use App\Game\AI\HeroTactics;
use App\Game\Player\HeroSkills;
use App\Game\Player\HeroType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Hero-skill and spell decisions of the virtual player.
 */
#[CoversClass(HeroTactics::class)]
final class HeroTacticsTest extends TestCase
{
    #[Test]
    public function aDrawIsWonWithOneFireball(): void
    {
        $decision = HeroTactics::afterRoll(7, 7, false, false, false, 5, [$this->spell('f1', 'fireball'), $this->spell('f2', 'fireball')]);

        self::assertTrue($decision['wins']);
        self::assertSame(['f1'], $decision['consumableIds']);
        self::assertFalse($decision['reroll']);
    }

    #[Test]
    public function aLossNeedsTotalAboveTheMonster(): void
    {
        $decision = HeroTactics::afterRoll(7, 5, false, false, false, 5, [$this->spell('f1', 'fireball'), $this->spell('f2', 'fireball')]);

        self::assertSame([], $decision['consumableIds'], 'Two fireballs reach a draw only: keep them');
        self::assertFalse($decision['wins']);
    }

    #[Test]
    public function backstabNeedsNoSpellForATie(): void
    {
        $decision = HeroTactics::afterRoll(7, 7, true, false, false, 5, [$this->spell('f1', 'fireball')]);

        self::assertTrue($decision['wins']);
        self::assertSame([], $decision['consumableIds']);
    }

    #[Test]
    public function oneFrostFistBeatsTwoFireballs(): void
    {
        $decision = HeroTactics::afterRoll(7, 6, false, false, false, 5, [
            $this->spell('f1', 'fireball'),
            $this->spell('f2', 'fireball'),
            $this->spell('ff', 'frost_fist'),
        ]);

        self::assertSame(['ff'], $decision['consumableIds']);
    }

    #[Test]
    public function doubleAttackOnlyWhenSpellsCannotWin(): void
    {
        $short = HeroTactics::afterRoll(9, 5, false, true, false, 5, [$this->spell('f1', 'fireball')]);
        self::assertTrue($short['reroll']);
        self::assertSame([], $short['consumableIds']);

        $closable = HeroTactics::afterRoll(7, 7, false, true, false, 5, [$this->spell('f1', 'fireball')]);
        self::assertFalse($closable['reroll']);
        self::assertSame(['f1'], $closable['consumableIds']);
    }

    #[Test]
    public function sacrificeTurnsADrawIntoAWinButNeverWithTheLastHp(): void
    {
        $paid = HeroTactics::afterRoll(7, 7, false, false, true, 2, []);
        self::assertTrue($paid['sacrifice']);
        self::assertTrue($paid['wins']);

        $lastHp = HeroTactics::afterRoll(7, 7, false, false, true, 1, []);
        self::assertFalse($lastHp['sacrifice']);
        self::assertFalse($lastHp['wins']);

        $spellFirst = HeroTactics::afterRoll(7, 7, false, false, true, 5, [$this->spell('f1', 'fireball')]);
        self::assertFalse($spellFirst['sacrifice'], 'A fireball is cheaper than 1 HP');
        self::assertSame(['f1'], $spellFirst['consumableIds']);

        $both = HeroTactics::afterRoll(7, 6, false, false, true, 5, [$this->spell('f1', 'fireball')]);
        self::assertTrue($both['sacrifice']);
        self::assertSame(['f1'], $both['consumableIds']);
    }

    #[Test]
    public function tirelessLeavesUnwantedLootWhileMovesRemain(): void
    {
        $valduk = HeroSkills::of(HeroType::BARBARIAN);

        self::assertTrue(HeroTactics::useTireless($valduk, false, 2));
        self::assertFalse(HeroTactics::useTireless($valduk, true, 2));
        self::assertFalse(HeroTactics::useTireless($valduk, false, 0));
        self::assertFalse(HeroTactics::useTireless(HeroSkills::of(HeroType::WARRIOR), false, 2));
    }

    #[Test]
    public function valdukSetsTheHealLimitOnce(): void
    {
        self::assertSame(4, HeroTactics::healLimit(HeroSkills::of(HeroType::BARBARIAN), null));
        self::assertNull(HeroTactics::healLimit(HeroSkills::of(HeroType::BARBARIAN), 4));
        self::assertNull(HeroTactics::healLimit(null, null), 'Cursed: nothing to set');
        self::assertNull(HeroTactics::healLimit(HeroSkills::of(HeroType::THIEF), null));
    }

    #[Test]
    public function darkThornStunsARivalAtOneHpThenHitsTheLeader(): void
    {
        $rivals = [
            ['playerId' => 'leader', 'hp' => 5, 'treasure' => 4, 'reincarnates' => false],
            ['playerId' => 'weak', 'hp' => 1, 'treasure' => 0, 'reincarnates' => false],
            ['playerId' => 'warrior', 'hp' => 1, 'treasure' => 9, 'reincarnates' => true],
            ['playerId' => 'stunned', 'hp' => 0, 'treasure' => 20, 'reincarnates' => false],
        ];

        self::assertSame(['weak', 'warrior', 'leader'], HeroTactics::darkThornTargets(3, $rivals));
    }

    #[Test]
    public function darkThornIsKeptWhenEveryRivalIsDown(): void
    {
        self::assertSame([], HeroTactics::darkThornTargets(2, [['playerId' => 'x', 'hp' => 0, 'treasure' => 1, 'reincarnates' => false]]));
    }

    #[Test]
    public function portalOfHealingWhenLowOrCursed(): void
    {
        $fountains = ['0,0', '4,0'];

        self::assertSame('4,0', HeroTactics::portalTarget(true, 2, false, '5,1', $fountains));
        self::assertSame('0,0', HeroTactics::portalTarget(true, 5, true, '1,1', $fountains));
        self::assertNull(HeroTactics::portalTarget(true, 5, false, '1,1', $fountains));
        self::assertNull(HeroTactics::portalTarget(true, 1, false, '4,0', $fountains), 'Already on a fountain');
        self::assertNull(HeroTactics::portalTarget(false, 1, false, '1,1', $fountains));
    }

    #[Test]
    public function magicSwapOntoAFountainWhenLow(): void
    {
        $warlock = HeroSkills::of(HeroType::WARLOCK);
        $rivals = [['playerId' => 'a', 'position' => '3,3'], ['playerId' => 'b', 'position' => '0,0']];

        self::assertSame('b', HeroTactics::magicSwapTarget($warlock, 1, '6,6', $rivals, ['0,0'], false));
        self::assertNull(HeroTactics::magicSwapTarget($warlock, 1, '6,6', $rivals, ['0,0'], true), 'A fountain is in reach anyway');
        self::assertNull(HeroTactics::magicSwapTarget($warlock, 5, '6,6', $rivals, ['0,0'], false));
        self::assertNull(HeroTactics::magicSwapTarget(HeroSkills::of(HeroType::THIEF), 1, '6,6', $rivals, ['0,0'], false));
    }

    #[Test]
    public function magicSwapNextToAWorthwhileDragon(): void
    {
        $warlock = HeroSkills::of(HeroType::WARLOCK);
        $rivals = [['playerId' => 'near', 'position' => '9,1']];

        self::assertSame('near', HeroTactics::magicSwapTarget($warlock, 5, '0,0', $rivals, [], false, '9,0', true));
        self::assertNull(HeroTactics::magicSwapTarget($warlock, 5, '0,0', $rivals, [], false, '9,0', false));
        self::assertNull(HeroTactics::magicSwapTarget($warlock, 5, '8,0', $rivals, [], false, '9,0', true), 'Already close');
    }

    #[Test]
    public function magicSwapNextToAChestWhenHoldingAKey(): void
    {
        $warlock = HeroSkills::of(HeroType::WARLOCK);
        $rivals = [['playerId' => 'near', 'position' => '8,1']];

        self::assertSame('near', HeroTactics::magicSwapTarget($warlock, 5, '0,0', $rivals, [], false, hasKey: true, chests: ['8,0']));
        self::assertNull(HeroTactics::magicSwapTarget($warlock, 5, '0,0', $rivals, [], false, hasKey: false, chests: ['8,0']));
        self::assertNull(HeroTactics::magicSwapTarget($warlock, 5, '7,0', $rivals, [], false, hasKey: true, chests: ['8,0']), 'A chest is close anyway');
    }

    #[Test]
    public function lightningAttackPicksTheMostValuableWinnableMonster(): void
    {
        $markul = HeroSkills::of(HeroType::BATTLE_MAGE);
        $monsters = [
            ['position' => '1,0', 'winChance' => 0.9, 'value' => 2],
            ['position' => '2,0', 'winChance' => 0.6, 'value' => 10],
            ['position' => '3,0', 'winChance' => 0.3, 'value' => 10],
            ['position' => '4,0', 'winChance' => 1.0, 'value' => 0],
        ];

        self::assertSame('2,0', HeroTactics::lightningTarget($markul, $monsters, 0.5));
        self::assertNull(HeroTactics::lightningTarget($markul, $monsters, 0.95));
        self::assertNull(HeroTactics::lightningTarget($markul, $monsters, 0.5, 1), 'Never at 1 HP');
        self::assertNull(HeroTactics::lightningTarget(HeroSkills::of(HeroType::RANGER), $monsters, 0.5));
    }

    #[Test]
    public function sprintOnlyForFiveToEightMoves(): void
    {
        $hanna = HeroSkills::of(HeroType::ACROBAT);

        self::assertFalse(HeroTactics::shouldSprint($hanna, 4));
        self::assertTrue(HeroTactics::shouldSprint($hanna, 5));
        self::assertTrue(HeroTactics::shouldSprint($hanna, 8));
        self::assertFalse(HeroTactics::shouldSprint($hanna, 9));
        self::assertFalse(HeroTactics::shouldSprint($hanna, null));
        self::assertFalse(HeroTactics::shouldSprint(HeroSkills::of(HeroType::THIEF), 6));
    }

    #[Test]
    public function stealthPastAFightTheThiefWouldRatherAvoid(): void
    {
        $aderyn = HeroSkills::of(HeroType::THIEF);

        self::assertTrue(HeroTactics::shouldSneak($aderyn, 0.3, 0.5));
        self::assertFalse(HeroTactics::shouldSneak($aderyn, 0.7, 0.5));
        self::assertFalse(HeroTactics::shouldSneak(HeroSkills::of(HeroType::WARRIOR), 0.1, 0.5));
    }

    #[Test]
    public function listeningAtABadFightOrAnyFightAtOneHp(): void
    {
        $lorraine = HeroSkills::of(HeroType::RANGER);

        self::assertTrue(HeroTactics::shouldListen($lorraine, 5, 0.3, 0.5));
        self::assertFalse(HeroTactics::shouldListen($lorraine, 5, 0.8, 0.5));
        self::assertTrue(HeroTactics::shouldListen($lorraine, 1, 0.8, 0.5));
        self::assertFalse(HeroTactics::shouldListen($lorraine, 1, null, 0.5), 'An empty room is entered');
        self::assertFalse(HeroTactics::shouldListen(HeroSkills::of(HeroType::THIEF), 1, 0.1, 0.5));
    }

    #[Test]
    public function retreatAndFountainChoices(): void
    {
        self::assertSame('2,0', HeroTactics::pickRetreat(['5,5', '2,0'], ['0,0']));
        self::assertSame('5,5', HeroTactics::pickRetreat(['5,5', '2,0'], []));
        self::assertNull(HeroTactics::pickRetreat([], ['0,0']));

        self::assertSame('6,0', HeroTactics::pickFountain(['0,0', '6,0'], '1,0', '7,0'), 'Nearest to the goal');
        self::assertSame('0,0', HeroTactics::pickFountain(['0,0', '6,0'], '1,0'));
        self::assertNull(HeroTactics::pickFountain([], '1,0'));
    }

    #[Test]
    public function aLordKarakUsesEverySkillHeTookOver(): void
    {
        $lordKarak = new HeroSkills([HeroType::BATTLE_MAGE, HeroType::ACROBAT, HeroType::BARBARIAN]);

        self::assertSame('1,0', HeroTactics::lightningTarget($lordKarak, [['position' => '1,0', 'winChance' => 0.9, 'value' => 3]], 0.5));
        self::assertTrue(HeroTactics::shouldSprint($lordKarak, 6));
        self::assertSame(4, HeroTactics::healLimit($lordKarak, null));
    }

    /**
     * @return array{itemId: string, type: string}
     */
    private function spell(string $id, string $type): array
    {
        return ['itemId' => $id, 'type' => $type];
    }
}
