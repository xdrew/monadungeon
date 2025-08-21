<?php

declare(strict_types=1);

namespace App\Game\Player\Error;

use App\Game\Item\Item;

final class MissingKeyException extends \Exception
{
    public function __construct(
        public readonly Item $chest,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Cannot open %s chest without a key', $chest->type->value),
            0,
            $previous,
        );
    }
}
