<?php

declare(strict_types=1);

namespace App\Api\Game\Fateweaver;

use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    public function __construct(
        public Uuid $gameId,
        public string $fieldPlace,
        public Item $item,
        public bool $success = true,
    ) {}
}
