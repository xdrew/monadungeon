<?php

declare(strict_types=1);

namespace App\Game\Testing;

final readonly class TestGameConfiguration
{
    /**
     * @param array<int> $diceRolls Predetermined dice rolls
     * @param array<string> $tileSequence Fixed order of tiles to be drawn
     * @param array<string> $itemSequence Fixed order of items in bag
     * @param array<string, PlayerTestConfig> $playerConfigs Player-specific test configurations
     * @param bool $regent Karak: Regent expansion on (needed for the Regent heroes)
     * @param bool $lordKarak Karak: Regent, Lord Karak variant on (needs $regent)
     * @param bool $randomHeroes Heroes dealt at random to players configured without a hero
     */
    public function __construct(
        public array $diceRolls = [],
        public array $tileSequence = [],
        public array $itemSequence = [],
        public array $playerConfigs = [],
        public bool $regent = false,
        public bool $lordKarak = false,
        public bool $randomHeroes = false,
    ) {}
}
