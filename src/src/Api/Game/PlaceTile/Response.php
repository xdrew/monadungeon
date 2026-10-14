<?php

declare(strict_types=1);

namespace App\Api\Game\PlaceTile;

use App\Game\Field\Tile;
use App\Game\Item\Item;

final readonly class Response
{
    /**
     * @param array{fieldPlace: string, items: list<Item>}|null $fateweaverChoice Oracle "Fateweaver": the two drawn tokens waiting for a choice
     */
    public function __construct(
        public Tile $tile,
        public ?array $fateweaverChoice = null,
    ) {}
}
