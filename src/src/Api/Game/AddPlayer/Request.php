<?php

declare(strict_types=1);

namespace App\Api\Game\AddPlayer;

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
        public ?string $externalId = null,
        #[Assert\Length(max: SeatIdentity::MAX_NAME_LENGTH)]
        public ?string $username = null,
        public bool $isAi = false,
        #[Assert\Choice(callback: [HeroType::class, 'values'])]
        public ?string $hero = null,
    ) {}
}
