<?php

declare(strict_types=1);

namespace App\Game\Player;

/**
 * Playable heroes and their skills (based on the Karak board game).
 */
enum HeroType: string
{
    case WARRIOR = 'warrior';

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
        };
    }

    /**
     * Double Attack: may reroll the dice once per combat; the second roll must be accepted.
     */
    public function canRerollBattle(): bool
    {
        return match ($this) {
            self::WARRIOR => true,
        };
    }

    /**
     * Reincarnation: on losing the last HP the hero teleports to a healing fountain with full HP instead of being stunned.
     */
    public function reincarnates(): bool
    {
        return match ($this) {
            self::WARRIOR => true,
        };
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
        };
    }
}
