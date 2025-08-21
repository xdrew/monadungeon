<?php

declare(strict_types=1);

namespace App\Api\Game\GetPlayerStatus;

use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        public Uuid $playerId,
    ) {}
}
