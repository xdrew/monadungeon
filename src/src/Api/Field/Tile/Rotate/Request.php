<?php

declare(strict_types=1);

namespace App\Api\Field\Tile\Rotate;

use App\Game\Field\TileSide;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $tileId,
        #[Assert\NotBlank]
        public TileSide $topSide,
        #[Assert\NotBlank]
        public TileSide $requiredOpenSide,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $gameId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $playerId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $turnId,
    ) {}
}
