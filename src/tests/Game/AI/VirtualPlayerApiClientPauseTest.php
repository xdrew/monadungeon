<?php

declare(strict_types=1);

namespace App\Tests\Game\AI;

use App\Game\AI\AiTurnPaused;
use App\Game\AI\VirtualPlayerApiClient;
use App\Game\Field\GetField;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Karak: Regent — while a duel waits for the human it summoned, the AI's turn pauses: its next request is not sent.
 */
#[CoversClass(VirtualPlayerApiClient::class)]
final class VirtualPlayerApiClientPauseTest extends TestCase
{
    private bool $awaits = false;

    private int $sent = 0;

    #[Test]
    public function theNextRequestPausesTheTurnWhileADuelWaitsForAHuman(): void
    {
        $this->awaits = true;

        try {
            $this->client()->endTurn(Uuid::v7(), Uuid::v7(), Uuid::v7());
            self::fail('The turn should pause');
        } catch (AiTurnPaused) {
        }
        self::assertSame(0, $this->sent, 'Nothing reaches the game, the turn is not ended');
    }

    #[Test]
    public function otherwiseTheRequestGoesThrough(): void
    {
        $result = $this->client()->endTurn(Uuid::v7(), Uuid::v7(), Uuid::v7());

        self::assertSame(1, $this->sent);
        self::assertTrue($result['success']);
    }

    public function countSent(): void
    {
        ++$this->sent;
    }

    private function client(): VirtualPlayerApiClient
    {
        $test = $this;
        $kernel = new class ($test) implements HttpKernelInterface {
            public function __construct(private readonly VirtualPlayerApiClientPauseTest $test) {}

            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $this->test->countSent();

                return new Response('{}', 200);
            }
        };
        $bus = MessageBusTester::create(
            fn(GetField $_query): object => new class ($test->awaits) {
                public function __construct(private readonly bool $awaits) {}

                public function duelAwaitsHuman(): bool
                {
                    return $this->awaits;
                }
            },
        )->messageBus();

        return new VirtualPlayerApiClient($kernel, $bus);
    }
}
