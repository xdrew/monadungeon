<?php

declare(strict_types=1);

namespace App\Api\Field\Tile\Place;

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
        public Uuid $tileId,
        #[Assert\NotBlank]
        public FieldPlace $fieldPlace,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $playerId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $turnId,
    ) {}
}
