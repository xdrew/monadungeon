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
     * @param array<string, array{maxHp?: int|null, maxActions?: int|null}> $playerConfigs
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
    ) {}
}
