<?php

declare(strict_types=1);

namespace App\Api\Testing\ToggleTestMode;

final readonly class Response
{
    public function __construct(
        public bool $enabled,
    ) {}
}
