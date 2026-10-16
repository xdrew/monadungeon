<?php

declare(strict_types=1);

namespace App\Infrastructure\Push;

/**
 * Pushes an update to every browser subscribed to a topic. Never throws: a game goes on without push.
 */
interface Publisher
{
    /**
     * @param array<string, mixed> $data
     */
    public function publish(string $topic, array $data): void;
}
