<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

interface StatusAwareResult
{
    /**
     * @return positive-int
     */
    public function statusCode(): int;
}
