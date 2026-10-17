<?php

declare(strict_types=1);

namespace App\Tests\Game\AI;

use App\Game\AI\BasicVirtualPlayerStrategy;
use App\Game\AI\SmartVirtualPlayer;
use App\Game\AI\VirtualPlayerApiClient;
use App\Game\Field\FieldPlace;
use App\Game\Movement\GetPlayerPosition;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * With a full pack the AI takes the item lying on its tile in exchange for a weaker one through the regular
 * pick-up (the item comes from the field, not from the client): there is no other way to put an item in a pack.
 */
#[CoversClass(SmartVirtualPlayer::class)]
final class AiItemReplacementTest extends TestCase
{
    #[Test]
    public function theAiSwapsThroughThePickUpOnItsOwnTile(): void
    {
        $requests = [];
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(static function (Request $request) use (&$requests): JsonResponse {
            $requests[] = ['uri' => $request->getPathInfo(), 'body' => json_decode((string) $request->getContent(), true)];

            return new JsonResponse(['item' => ['type' => 'axe']]);
        });
        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $bus = MessageBusTester::create(static fn(GetPlayerPosition $_query): FieldPlace => new FieldPlace(2, -1))->messageBus();
        $ai = new SmartVirtualPlayer($bus, new BasicVirtualPlayerStrategy(), new VirtualPlayerApiClient($kernel));

        $actions = [];
        $replace = new \ReflectionMethod(SmartVirtualPlayer::class, 'callInventoryReplaceAction');
        $replace->invokeArgs($ai, [$gameId, $playerId, Uuid::v7(), ['itemId' => Uuid::v7()->toString(), 'type' => 'axe'], 'dagger-id', &$actions]);

        self::assertCount(1, $requests);
        self::assertSame('/api/game/pick-item', $requests[0]['uri']);
        self::assertSame('2,-1', $requests[0]['body']['position'] ?? null, 'From the tile the AI stands on');
        self::assertSame('dagger-id', $requests[0]['body']['itemIdToReplace'] ?? null);
    }
}
