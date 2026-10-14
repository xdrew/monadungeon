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
     * Skill flags for API consumers (frontend decides which prompts/buttons to show).
     *
     * @return array{canRerollBattle: bool, reincarnates: bool, winsTies: bool, canSneakPastMonsters: bool}
     */
    public function flags(): array
    {
        return [
            'canRerollBattle' => $this->canRerollBattle(),
            'reincarnates' => $this->reincarnates(),
            'winsTies' => $this->winsTies(),
            'canSneakPastMonsters' => $this->canSneakPastMonsters(),
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
        };
    }
}
