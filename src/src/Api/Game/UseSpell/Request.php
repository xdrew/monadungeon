<?php

declare(strict_types=1);

namespace App\Api\Game\UseSpell;

use App\Game\Field\FieldPlace;
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
        #[Assert\Uuid]
        public Uuid $turnId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $spellId,
        public ?FieldPlace $targetPosition = null,
    ) {}
}
