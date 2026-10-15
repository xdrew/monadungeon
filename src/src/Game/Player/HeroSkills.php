<?php

declare(strict_types=1);

namespace App\Game\Player;

/**
 * The skills a hero can use right now: normally those of the chosen hero, for Lord Karak those of several heroes.
 *
 * Karak: Regent, Lord Karak variant — "loses the original abilities (exception: with 2 players he keeps them),
 * gains the abilities of all other heroes in the game". The methods mirror HeroType, so every skill check reads
 * `$player->getSkills()?->skill()` whoever plays.
 */
final readonly class HeroSkills
{
    /**
     * @param list<HeroType> $heroes
     */
    public function __construct(
        public array $heroes,
    ) {}

    public static function of(HeroType $hero): self
    {
        return new self([$hero]);
    }

    public function label(): string
    {
        return \count($this->heroes) === 1 ? $this->heroes[0]->label() : 'Lord Karak';
    }

    public function canRerollBattle(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->canRerollBattle());
    }

    public function reincarnates(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->reincarnates());
    }

    public function winsTies(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->winsTies());
    }

    public function canSneakPastMonsters(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->canSneakPastMonsters());
    }

    public function rerollsOnes(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->rerollsOnes());
    }

    public function continuesTurnOnSix(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->continuesTurnOnSix());
    }

    public function sacrificesHp(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->sacrificesHp());
    }

    public function canMagicSwap(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->canMagicSwap());
    }

    public function keepsSpellsInCombat(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->keepsSpellsInCombat());
    }

    public function walksThroughWalls(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->walksThroughWalls());
    }

    public function fightsBetterOnFirstMove(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->fightsBetterOnFirstMove());
    }

    public function drawsTwoTokens(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->drawsTwoTokens());
    }

    public function berserk(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->berserk());
    }

    public function berserkBonus(int $hp): int
    {
        $bonus = 0;
        foreach ($this->heroes as $hero) {
            $bonus = max($bonus, $hero->berserkBonus($hp));
        }

        return $bonus;
    }

    public function choosesHealing(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->choosesHealing());
    }

    public function tireless(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->tireless());
    }

    public function masterOfSword(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->masterOfSword());
    }

    public function lightningAttack(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->lightningAttack());
    }

    public function throwsDaggers(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->throwsDaggers());
    }

    public function sprints(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->sprints());
    }

    public function bearAttack(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->bearAttack());
    }

    public function listens(): bool
    {
        return $this->any(static fn(HeroType $hero): bool => $hero->listens());
    }

    /**
     * Skill flags for API consumers: a flag is on when any of the heroes has it.
     *
     * @return array<string, bool>
     */
    public function flags(): array
    {
        $flags = HeroType::WARRIOR->flags();
        foreach (array_keys($flags) as $flag) {
            $flags[$flag] = false;
        }
        foreach ($this->heroes as $hero) {
            foreach ($hero->flags() as $flag => $on) {
                $flags[$flag] = $flags[$flag] || $on;
            }
        }

        return $flags;
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    public function skills(): array
    {
        $skills = [];
        foreach ($this->heroes as $hero) {
            foreach ($hero->skills() as $skill) {
                $skills[$skill['name']] ??= $skill;
            }
        }

        return array_values($skills);
    }

    /**
     * @param callable(HeroType): bool $skill
     */
    private function any(callable $skill): bool
    {
        foreach ($this->heroes as $hero) {
            if ($skill($hero)) {
                return true;
            }
        }

        return false;
    }
}
