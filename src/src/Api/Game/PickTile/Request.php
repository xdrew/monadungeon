<?php

declare(strict_types=1);

namespace App\Api\Game\PickTile;

use App\Game\Field\FieldPlace;
use App\Game\Field\TileSide;
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
        #[Assert\Uuid]
        public Uuid $playerId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $turnId,
        #[Assert\NotBlank]
        public TileSide $requiredOpenSide,
        #[Assert\NotBlank]
        public FieldPlace $fieldPlace,
    ) {}
}
