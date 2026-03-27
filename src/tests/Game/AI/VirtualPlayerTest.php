<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\VirtualPlayer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VirtualPlayer::class)]
class VirtualPlayerTest extends TestCase
{
    #[Test]
    public function pendingTests(): void
    {
        self::markTestSkipped('Tests pending rewrite — need MessageBusTester approach instead of mocks');
    }
}
