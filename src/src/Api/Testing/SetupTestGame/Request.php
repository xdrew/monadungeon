<?php

declare(strict_types=1);

namespace App\Api\Testing\SetupTestGame;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    /**
     * @param array<int> $diceRolls
     * @param array<string> $tileSequence
     * @param array<string> $itemSequence
     * @param array<string, array{maxHp?: int|null, maxActions?: int|null, hero?: string|null, hp?: int|null, inventory?: list<string|array{type: string, treasureValue?: int}>}> $playerConfigs
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $gameId,
        #[Assert\Type('array')]
        public array $diceRolls = [],
        #[Assert\Type('array')]
        public array $tileSequence = [],
        #[Assert\Type('array')]
        public array $itemSequence = [],
        #[Assert\Type('array')]
        public array $playerConfigs = [],
        /**
         * Karak: Regent expansion on (needed for the Regent heroes).
         */
        public bool $regent = false,
        /**
         * Karak: Regent, Lord Karak variant on (needs regent).
         */
        public bool $lordKarak = false,
        /**
         * Heroes dealt at random to players configured without a hero.
         */
        public bool $randomHeroes = false,
    ) {}
}
