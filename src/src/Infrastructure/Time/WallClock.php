<?php

declare(strict_types=1);

namespace App\Infrastructure\Time;

use Psr\Clock\ClockInterface;

final readonly class WallClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
