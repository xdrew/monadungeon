<?php

declare(strict_types=1);

namespace App\Api\Game\EndTurn;

use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        public Uuid $gameId,
        #[Assert\NotBlank]
        public Uuid $playerId,
        #[Assert\NotBlank]
        public Uuid $turnId,
    ) {}
}
