<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Push;

use App\Infrastructure\Push\MercurePublisher;
use App\Infrastructure\Push\Topics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(MercurePublisher::class)]
final class MercurePublisherTest extends TestCase
{
    #[Test]
    public function aHubThatIsDownIsLoggedAndSwallowed(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $publisher = new MercurePublisher('http://127.0.0.1:9/.well-known/mercure', str_repeat('k', 32), $logger);

        $started = microtime(true);
        $publisher->publish(Topics::game('g-1'), ['type' => 'game.changed']);

        self::assertLessThan(3.0, microtime(true) - $started, 'A publish waits two seconds at most');
        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame('warning', $level);
        self::assertSame('push.failed', $message);
        self::assertSame('karak/game/g-1', $context['topic']);
        self::assertSame('game.changed', $context['type']);
    }

    #[Test]
    public function topicsAreNamedByGame(): void
    {
        self::assertSame('karak/game/abc', Topics::game('abc'));
        self::assertSame('karak/tables', Topics::TABLES);
    }
}
