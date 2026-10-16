<?php

declare(strict_types=1);

namespace App\Game\Testing;

final readonly class PlayerTestConfig
{
    /**
     * @param int|null $maxHp Player's max HP (null = default)
     * @param int|null $maxActions Max actions per turn (null = default)
     * @param string|null $hero Hero type value (see HeroType), null = no hero
     * @param int|null $hp Current HP at the start (null = full), at most the max HP
     * @param list<string|array{type: string, treasureValue?: int}> $inventory items already in the pack (see TestPackItem)
     */
    public function __construct(
        public ?int $maxHp = null,
        public ?int $maxActions = null,
        public ?string $hero = null,
        public ?int $hp = null,
        public array $inventory = [],
    ) {}
}
