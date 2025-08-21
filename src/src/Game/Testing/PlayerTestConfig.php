<?php

declare(strict_types=1);

namespace App\Game\Testing;

final readonly class PlayerTestConfig
{
    /**
     * @param int|null $maxHp Player's max HP (null = default)
     * @param int|null $maxActions Max actions per turn (null = default)
     */
    public function __construct(
        public ?int $maxHp = null,
        public ?int $maxActions = null,
    ) {}
}
