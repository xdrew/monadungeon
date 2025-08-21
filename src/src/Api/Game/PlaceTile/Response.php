<?php

declare(strict_types=1);

namespace App\Api\Game\PlaceTile;

use App\Game\Field\Tile;

final readonly class Response
{
    public function __construct(
        public Tile $tile,
    ) {}
}
