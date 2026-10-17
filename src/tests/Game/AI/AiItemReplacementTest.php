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
 * The AI plays by the server's rules: with a full pack it swaps through the regular pick-up on its own tile, it
 * counts each spell once in its fight odds, and it stops once the server has ended its turn (a pickup, a duel).
 */
#[CoversClass(SmartVirtualPlayer::class)]
final class AiItemReplacementTest extends TestCase
{
    #[Test]
    public function theAiCountsItsFireballOnce(): void
    {
        $player = \App\Game\Player\Player::onPlayerAddedToGame(new \App\Game\GameLifecycle\PlayerAdded(Uuid::v7(), Uuid::v7()));
        (new \ReflectionProperty(\App\Game\Player\Player::class, 'inventory'))->setValue($player, [
            'key' => [], 'weapon' => [], 'treasure' => [],
            'spell' => [new \App\Game\Item\Item(\App\Game\Item\ItemName::MUMMY, \App\Game\Item\ItemType::FIREBALL, 7, 0, true)],
        ]);
        $ai = new SmartVirtualPlayer(MessageBusTester::create()->messageBus(), new BasicVirtualPlayerStrategy(), new VirtualPlayerApiClient($this->createMock(HttpKernelInterface::class)));

        $chance = (new \ReflectionMethod(SmartVirtualPlayer::class, 'fightWinChance'))->invoke($ai, $player, 12);

        // 2d6 + 1 beats 12 only with 12: 1/36 (with the fireball counted twice it would be 3/36)
        self::assertEqualsWithDelta(\App\Game\AI\BattleOdds::winChance(monsterHp: 12, itemDamage: 0, skills: null, hp: 5, fireballs: 1, frostFists: 0, firstMove: true), $chance, 0.0001);
        self::assertEqualsWithDelta(1 / 36, $chance, 0.0001);
    }

    #[Test]
    public function theAiStopsOnceTheServerEndedItsTurn(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');
        $bus = MessageBusTester::create(static fn(\App\Game\Turn\GetCurrentTurn $_query): Uuid => Uuid::v7())->messageBus();
        $ai = new SmartVirtualPlayer($bus, new BasicVirtualPlayerStrategy(), new VirtualPlayerApiClient($kernel));

        $actions = [];
        $continue = new \ReflectionMethod(SmartVirtualPlayer::class, 'continueAfterAction');
        $continue->invokeArgs($ai, [Uuid::v7(), Uuid::v7(), Uuid::v7(), &$actions]);

        self::assertSame('The turn is over', $actions[0]['details']['message'] ?? null);
    }

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
