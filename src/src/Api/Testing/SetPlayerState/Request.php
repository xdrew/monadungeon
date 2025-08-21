<?php

declare(strict_types=1);

namespace App\Api\Testing\SetPlayerState;

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
        #[Assert\Type('int')]
        #[Assert\GreaterThanOrEqual(1)]
        public ?int $maxHp = null,
        #[Assert\Type('int')]
        #[Assert\GreaterThanOrEqual(1)]
        public ?int $maxActions = null,
    ) {}
}
