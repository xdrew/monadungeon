<?php

declare(strict_types=1);

namespace App\Game\Player;

/**
 * Playable heroes and their skills (based on the Karak board game).
 */
enum HeroType: string
{
    case WARRIOR = 'warrior';
    case THIEF = 'thief';
    case SWORDSMAN = 'swordsman';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $hero): string => $hero->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::WARRIOR => 'Horan, the Warrior',
            self::THIEF => 'Aderyn, the Thief',
            self::SWORDSMAN => 'Victorius, the Swordsman',
        };
    }

    /**
     * Double Attack: may reroll the dice once per combat; the second roll must be accepted.
     */
    public function canRerollBattle(): bool
    {
        return $this === self::WARRIOR;
    }

    /**
     * Reincarnation: on losing the last HP the hero teleports to a healing fountain with full HP instead of being stunned.
     */
    public function reincarnates(): bool
    {
        return $this === self::WARRIOR;
    }

    /**
     * Backstab: a combat that would be a tie is a victory.
     */
    public function winsTies(): bool
    {
        return $this === self::THIEF;
    }

    /**
     * Stealth: may enter a tile with a monster without fighting it and keep moving.
     */
    public function canSneakPastMonsters(): bool
    {
        return $this === self::THIEF;
    }

    /**
     * Combat Training: every die showing 1 is rerolled until it shows something better.
     */
    public function rerollsOnes(): bool
    {
        return $this === self::SWORDSMAN;
    }

    /**
     * Unstoppable: when at least one die shows 6 the turn continues after the combat, whatever its result.
     */
    public function continuesTurnOnSix(): bool
    {
        return $this === self::SWORDSMAN;
    }

    /**
     * Skill flags for API consumers (frontend decides which prompts/buttons to show).
     *
     * @return array{canRerollBattle: bool, reincarnates: bool, winsTies: bool, canSneakPastMonsters: bool, rerollsOnes: bool, continuesTurnOnSix: bool}
     */
    public function flags(): array
    {
        return [
            'canRerollBattle' => $this->canRerollBattle(),
            'reincarnates' => $this->reincarnates(),
            'winsTies' => $this->winsTies(),
            'canSneakPastMonsters' => $this->canSneakPastMonsters(),
            'rerollsOnes' => $this->rerollsOnes(),
            'continuesTurnOnSix' => $this->continuesTurnOnSix(),
        ];
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    public function skills(): array
    {
        return match ($this) {
            self::WARRIOR => [
                [
                    'name' => 'Double Attack',
                    'description' => 'In any combat, the Warrior can reroll the dice if the first roll is not to his liking. He does not lose a HP in the first loss, but must accept the second roll\'s result.',
                ],
                [
                    'name' => 'Reincarnation',
                    'description' => 'If the Warrior loses his last HP, he does not fall unconscious. Instead he teleports to any tile with a healing fountain, where all his HP are replenished. His turn ends anyway.',
                ],
            ],
            self::THIEF => [
                [
                    'name' => 'Backstab',
                    'description' => 'The Thief attacks from the shadows: she wins any combat that would normally result in a tie.',
                ],
                [
                    'name' => 'Stealth',
                    'description' => 'The Thief moves in the shadows: when entering a tile with a monster, she can decide to fight it or to sneak past and continue moving.',
                ],
            ],
            self::SWORDSMAN => [
                [
                    'name' => 'Combat Training',
                    'description' => 'The Swordsman is an experienced fighter and his strikes are always precise: any die that shows a 1 is rerolled as many times as necessary.',
                ],
                [
                    'name' => 'Unstoppable',
                    'description' => 'If at least one die shows a 6 during combat, the Swordsman continues his turn afterwards. He may even fight the same monster again after a tie or a loss.',
                ],
            ],
        };
    }
}
