<?php

declare(strict_types=1);

namespace App\Api\Game\AddPlayer;

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
        public ?string $username = null,
        public ?string $walletAddress = null,
    ) {}
}
