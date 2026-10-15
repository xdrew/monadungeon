<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\HealLimit;

use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $gameId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $playerId,
        /**
         * Heal only up to this many HP; null = heal fully.
         */
        #[Assert\Range(min: 1, max: 5)]
        public ?int $hp = null,
    ) {}
}
