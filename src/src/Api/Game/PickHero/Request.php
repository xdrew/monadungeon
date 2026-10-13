<?php

declare(strict_types=1);

namespace App\Api\Game\PickHero;

use App\Game\Player\HeroType;
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
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [HeroType::class, 'values'])]
        public string $hero,
    ) {}
}
