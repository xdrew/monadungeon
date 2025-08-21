<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Logging;

use Psr\Log\AbstractLogger;

final class TestLogger extends AbstractLogger
{
    /**
     * @var list<array{mixed, string, array}>
     */
    private array $logs = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->logs[] = [$level, (string) $message, $context];
    }

    /**
     * @return list<string>
     */
    public function flushMessages(): array
    {
        try {
            return array_column($this->logs, 1);
        } finally {
            $this->logs = [];
        }
    }
}
