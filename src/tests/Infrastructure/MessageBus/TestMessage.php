<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MessageBus;

use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class TestMessage implements Message
{
    public function __construct(
        public mixed $data = null,
    ) {}
}
