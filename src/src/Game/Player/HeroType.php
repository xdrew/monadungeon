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
    case WARLOCK = 'warlock';
    case WIZARD = 'wizard';
    case ORACLE = 'oracle';

    // Karak: Regent heroes (only in games with the expansion)
    case BARBARIAN = 'barbarian';
    case BATTLE_MAGE = 'battle_mage';
    case ACROBAT = 'acrobat';
    case RANGER = 'ranger';

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
            self::WARLOCK => 'Lord Xanros, the Warlock',
            self::WIZARD => 'Argentus, the Wizard',
            self::ORACLE => 'Taia, the Oracle',
            self::BARBARIAN => 'Valduk, the Barbarian',
            self::BATTLE_MAGE => 'Baron Markul, the Battle Mage',
            self::ACROBAT => 'Hanna, the Acrobat',
            self::RANGER => 'Lady Lorraine, the Ranger',
        };
    }

    /**
     * Karak: Regent heroes can only be chosen when the expansion is on.
     */
    public function isRegent(): bool
    {
        return \in_array($this, [self::BARBARIAN, self::BATTLE_MAGE, self::ACROBAT, self::RANGER], true);
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
     * Sacrifice: after the dice are rolled the hero may pay 1 HP for +1 strength, once per combat.
     */
    public function sacrificesHp(): bool
    {
        return $this === self::WARLOCK;
    }

    /**
     * Magic Swap: may trade places with any other hero at the beginning of the turn, for the cost of all 4 moves.
     */
    public function canMagicSwap(): bool
    {
        return $this === self::WARLOCK;
    }

    /**
     * Magical Affinity: fireballs used in combat stay in the inventory (the teleport scroll is still consumed).
     */
    public function keepsSpellsInCombat(): bool
    {
        return $this === self::WIZARD;
    }

    /**
     * Astral Walking: may move to an adjacent placed tile without a door, as a normal move.
     */
    public function walksThroughWalls(): bool
    {
        return $this === self::WIZARD;
    }

    /**
     * Farseeing: +1 to the roll when the battle starts before any move of the turn was spent.
     */
    public function fightsBetterOnFirstMove(): bool
    {
        return $this === self::ORACLE;
    }

    /**
     * Fateweaver: draws two tokens from the bag, places one and returns the other.
     */
    public function drawsTwoTokens(): bool
    {
        return $this === self::ORACLE;
    }

    /**
     * Berserk: +1 to the roll at exactly 3–4 HP, +2 at 1–2 HP.
     */
    public function berserk(): bool
    {
        return $this === self::BARBARIAN;
    }

    /**
     * Berserk strength bonus for the HP the hero has when the dice are rolled.
     */
    public function berserkBonus(int $hp): int
    {
        if (!$this->berserk()) {
            return 0;
        }

        return match (true) {
            $hp === 3, $hp === 4 => 1,
            $hp === 1, $hp === 2 => 2,
            default => 0,
        };
    }

    /**
     * Berserk: when healing the hero may choose how many HP to heal (a "heal up to N" setting).
     */
    public function choosesHealing(): bool
    {
        return $this === self::BARBARIAN;
    }

    /**
     * Tireless: after defeating a monster and leaving the loot, the hero may continue his remaining moves.
     */
    public function tireless(): bool
    {
        return $this === self::BARBARIAN;
    }

    /**
     * Sword Master: a sword has strength 3 in his hands.
     */
    public function masterOfSword(): bool
    {
        return $this === self::BATTLE_MAGE;
    }

    /**
     * Lightning Attack: for all 4 moves, teleport to any tile with a monster and fight it; after a loss he chooses where to retreat.
     */
    public function lightningAttack(): bool
    {
        return $this === self::BATTLE_MAGE;
    }

    /**
     * Dagger Throwing: daggers may lie in the spell slots, each adds +1 and is never consumed.
     */
    public function throwsDaggers(): bool
    {
        return $this === self::ACROBAT;
    }

    /**
     * Sprint: declared at the start of the turn, up to 8 moves over discovered tiles only.
     */
    public function sprints(): bool
    {
        return $this === self::ACROBAT;
    }

    /**
     * Bear Attack: a die showing 1 counts as 6.
     */
    public function bearAttack(): bool
    {
        return $this === self::RANGER;
    }

    /**
     * Listening: for one move, explore an adjacent tile (place it, draw its token) without entering it.
     */
    public function listens(): bool
    {
        return $this === self::RANGER;
    }

    /**
     * Skill flags for API consumers (frontend decides which prompts/buttons to show).
     *
     * @return array{canRerollBattle: bool, reincarnates: bool, winsTies: bool, canSneakPastMonsters: bool, rerollsOnes: bool, continuesTurnOnSix: bool, sacrificesHp: bool, canMagicSwap: bool, keepsSpellsInCombat: bool, walksThroughWalls: bool, fightsBetterOnFirstMove: bool, drawsTwoTokens: bool, berserk: bool, choosesHealing: bool, tireless: bool, masterOfSword: bool, lightningAttack: bool, throwsDaggers: bool, sprints: bool, bearAttack: bool, listens: bool}
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
            'sacrificesHp' => $this->sacrificesHp(),
            'canMagicSwap' => $this->canMagicSwap(),
            'keepsSpellsInCombat' => $this->keepsSpellsInCombat(),
            'walksThroughWalls' => $this->walksThroughWalls(),
            'fightsBetterOnFirstMove' => $this->fightsBetterOnFirstMove(),
            'drawsTwoTokens' => $this->drawsTwoTokens(),
            'berserk' => $this->berserk(),
            'choosesHealing' => $this->choosesHealing(),
            'tireless' => $this->tireless(),
            'masterOfSword' => $this->masterOfSword(),
            'lightningAttack' => $this->lightningAttack(),
            'throwsDaggers' => $this->throwsDaggers(),
            'sprints' => $this->sprints(),
            'bearAttack' => $this->bearAttack(),
            'listens' => $this->listens(),
        ];
    }

    /**
     * @return list<array{key: non-empty-string, name: string, description: string}>
     */
    public function skills(): array
    {
        return match ($this) {
            self::WARRIOR => [
                [
                    'key' => 'double_attack',
                    'name' => 'Double Attack',
                    'description' => 'In any combat, the Warrior can reroll the dice if the first roll is not to his liking. He does not lose a HP in the first loss, but must accept the second roll\'s result.',
                ],
                [
                    'key' => 'reincarnation',
                    'name' => 'Reincarnation',
                    'description' => 'If the Warrior loses his last HP, he does not fall unconscious. Instead he teleports to any tile with a healing fountain, where all his HP are replenished. His turn ends anyway.',
                ],
            ],
            self::THIEF => [
                [
                    'key' => 'backstab',
                    'name' => 'Backstab',
                    'description' => 'The Thief attacks from the shadows: she wins any combat that would normally result in a tie.',
                ],
                [
                    'key' => 'stealth',
                    'name' => 'Stealth',
                    'description' => 'The Thief moves in the shadows: when entering a tile with a monster, she can decide to fight it or to sneak past and continue moving.',
                ],
            ],
            self::SWORDSMAN => [
                [
                    'key' => 'combat_training',
                    'name' => 'Combat Training',
                    'description' => 'The Swordsman is an experienced fighter and his strikes are always precise: any die that shows a 1 is rerolled as many times as necessary.',
                ],
                [
                    'key' => 'unstoppable',
                    'name' => 'Unstoppable',
                    'description' => 'If at least one die shows a 6 during combat, the Swordsman continues his turn afterwards. He may even fight the same monster again after a tie or a loss.',
                ],
            ],
            self::WARLOCK => [
                [
                    'key' => 'sacrifice',
                    'name' => 'Sacrifice',
                    'description' => 'The Warlock can sacrifice one HP to gain a bonus of +1 to his strength. He decides after rolling the dice and gains the bonus only once per combat. He can even sacrifice his last HP, but then falls unconscious for his next turn.',
                ],
                [
                    'key' => 'magic_swap',
                    'name' => 'Magic Swap',
                    'description' => 'The Warlock can swap his position with any other hero. This costs 4 moves, so it is only possible at the beginning of his turn; he can still use the ability of the tile where he ended up (unlocking a chest, healing, etc.).',
                ],
            ],
            self::WIZARD => [
                [
                    'key' => 'magical_affinity',
                    'name' => 'Magical Affinity',
                    'description' => 'The Wizard knows how to use the magic bolts more effectively: his fireball scrolls are not consumed when used in combat. This does not apply to the portal of healing.',
                ],
                [
                    'key' => 'astral_walking',
                    'name' => 'Astral Walking',
                    'description' => 'The Wizard can move through walls, so he does not have to respect the continuity of the dungeon. This can be used only while moving over the discovered tiles.',
                ],
            ],
            self::ORACLE => [
                [
                    'key' => 'farseeing',
                    'name' => 'Farseeing',
                    'description' => 'The Oracle has the best knowledge of all her enemies: if she fights in the first move of her turn, she gains a bonus of +1 to her roll.',
                ],
                [
                    'key' => 'fateweaver',
                    'name' => 'Fateweaver',
                    'description' => 'The Oracle can influence her future: whenever she is supposed to draw a token from the bag, she draws two instead, places one on the tile and puts the other one back in the bag.',
                ],
            ],
            self::BARBARIAN => [
                [
                    'key' => 'berserk',
                    'name' => 'Berserk',
                    'description' => 'The weaker the Barbarian gets, the harder he hits: with exactly 3 or 4 HP he gains +1 to his strength, with 1 or 2 HP +2. When healing he may choose how many HP to heal.',
                ],
                [
                    'key' => 'tireless',
                    'name' => 'Tireless',
                    'description' => 'If the Barbarian defeats a monster and does not take its loot, he may continue his remaining moves.',
                ],
            ],
            self::BATTLE_MAGE => [
                [
                    'key' => 'sword_master',
                    'name' => 'Sword Master',
                    'description' => 'A sword has strength 3 in the hands of the Battle Mage.',
                ],
                [
                    'key' => 'lightning_attack',
                    'name' => 'Lightning Attack',
                    'description' => 'For all 4 moves the Battle Mage teleports to any tile with a monster and fights it. If he does not win, he chooses where to retreat, only onto an already opened tile.',
                ],
            ],
            self::ACROBAT => [
                [
                    'key' => 'dagger_throwing',
                    'name' => 'Dagger Throwing',
                    'description' => 'The Acrobat may keep daggers (strength 1) in her spell slots. Every dagger she carries adds +1 to her strength and is never consumed.',
                ],
                [
                    'key' => 'sprint',
                    'name' => 'Sprint',
                    'description' => 'Instead of normal movement the Acrobat may declare a sprint at the start of her turn: up to 8 moves, but she may not explore undiscovered tiles.',
                ],
            ],
            self::RANGER => [
                [
                    'key' => 'bear_attack',
                    'name' => 'Bear Attack',
                    'description' => 'The Ranger fights alongside her bear: a die showing 1 counts as 6.',
                ],
                [
                    'key' => 'listening',
                    'name' => 'Listening',
                    'description' => 'For one move the Ranger may explore an adjacent tile (place it and draw its token) without entering it. Entering later costs another move. She may listen several times per turn.',
                ],
            ],
        };
    }
}
