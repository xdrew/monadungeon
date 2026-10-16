<?php

declare(strict_types=1);

namespace App\Api\Game\Duel\Prepare;

use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    /**
     * @param list<string> $consumableIds the damage spells spent in the duel, chosen before the roll
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $gameId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $playerId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $turnId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $duelId,
        #[Assert\All([new Assert\Uuid()])]
        public array $consumableIds = [],
        public bool $sacrifice = false,
    ) {}
}
