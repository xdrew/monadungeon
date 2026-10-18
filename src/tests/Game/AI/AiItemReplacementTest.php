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
    public function aSprintThatWouldStopOnTheWayIsNoRunToTheGoal(): void
    {
        $field = \App\Game\Field\Field::create(new \App\Game\GameLifecycle\GameCreated(Uuid::v7(), new \DateTimeImmutable(), 10), \App\Tests\Infrastructure\MessageBus\startMessageContext());
        (new \ReflectionProperty(\App\Game\Field\Field::class, 'tiles'))->setValue($field, ['0,0' => Uuid::v7(), '1,0' => Uuid::v7(), '2,0' => Uuid::v7()]);
        (new \ReflectionProperty(\App\Game\Field\Field::class, 'transitions'))->setValue($field, ['0,0' => ['1,0'], '1,0' => ['0,0', '2,0'], '2,0' => ['1,0']]);
        $ai = new SmartVirtualPlayer(MessageBusTester::create()->messageBus(), new BasicVirtualPlayerStrategy(), new VirtualPlayerApiClient($this->createMock(HttpKernelInterface::class)));
        $nearest = new \ReflectionMethod(SmartVirtualPlayer::class, 'nearestReachable');

        self::assertSame(['0,0', '1,0', '2,0'], $nearest->invoke($ai, '0,0', ['2,0'], $field, 8, []));
        self::assertNull($nearest->invoke($ai, '0,0', ['2,0'], $field, 8, ['1,0']), 'A Cursed Room on the way');
        self::assertSame(['0,0', '1,0'], $nearest->invoke($ai, '0,0', ['1,0'], $field, 8, ['1,0']), 'The goal itself may be one');
    }

    #[Test]
    public function theAiHeadsForAnOpenSideNotForAnEmptyPlaceBehindAWall(): void
    {
        $field = \App\Game\Field\Field::create(new \App\Game\GameLifecycle\GameCreated(Uuid::v7(), new \DateTimeImmutable(), 10), \App\Tests\Infrastructure\MessageBus\startMessageContext());
        // -1,0 has empty places around it, all behind walls; 2,0 opens onto the empty 3,0
        (new \ReflectionProperty(\App\Game\Field\Field::class, 'tiles'))->setValue($field, ['-1,0' => Uuid::v7(), '0,0' => Uuid::v7(), '1,0' => Uuid::v7(), '2,0' => Uuid::v7()]);
        (new \ReflectionProperty(\App\Game\Field\Field::class, 'transitions'))->setValue($field, ['-1,0' => ['0,0'], '0,0' => ['-1,0', '1,0'], '1,0' => ['0,0', '2,0'], '2,0' => ['1,0', '3,0']]);
        $ai = new SmartVirtualPlayer(MessageBusTester::create()->messageBus(), new BasicVirtualPlayerStrategy(), new VirtualPlayerApiClient($this->createMock(HttpKernelInterface::class)));

        $edges = (new \ReflectionMethod(SmartVirtualPlayer::class, 'findEdgePositions'))->invoke($ai, ['-1,0', '1,0', '2,0'], $field);
        $step = (new \ReflectionMethod(SmartVirtualPlayer::class, 'stepTowardsFrontier'))->invoke($ai, '-1,0', ['0,0'], $field);

        self::assertSame(['2,0'], $edges);
        self::assertSame('0,0', $step, 'The first step of the way to 2,0');
    }

    #[Test]
    public function aFightWonWithASpellOnAFullWeaponSlotGivesTheWeakestWeaponForTheReward(): void
    {
        $player = \App\Game\Player\Player::onPlayerAddedToGame(new \App\Game\GameLifecycle\PlayerAdded(Uuid::v7(), Uuid::v7()));
        $dagger = new \App\Game\Item\Item(\App\Game\Item\ItemName::GIANT_RAT, \App\Game\Item\ItemType::DAGGER, 5, 0, true);
        $fireball = new \App\Game\Item\Item(\App\Game\Item\ItemName::MUMMY, \App\Game\Item\ItemType::FIREBALL, 7, 0, true);
        (new \ReflectionProperty(\App\Game\Player\Player::class, 'inventory'))->setValue($player, [
            'key' => [], 'treasure' => [],
            'weapon' => [new \App\Game\Item\Item(\App\Game\Item\ItemName::SKELETON_KING, \App\Game\Item\ItemType::AXE, 10, 0, true), $dagger],
            'spell' => [$fireball, $fireball, $fireball],
        ]);
        $ai = new SmartVirtualPlayer(MessageBusTester::create()->messageBus(), new BasicVirtualPlayerStrategy(), new VirtualPlayerApiClient($this->createMock(HttpKernelInterface::class)));
        $choice = new \ReflectionMethod(SmartVirtualPlayer::class, 'rewardSlotChoice');

        self::assertSame([true, $dagger->itemId->toString()], $choice->invoke($ai, 'sword', $player, []));
        self::assertSame([false, null], $choice->invoke($ai, 'dagger', $player, []), 'Nothing weaker to give');
        self::assertSame([true, null], $choice->invoke($ai, 'fireball', $player, [$fireball->itemId->toString()]), 'The spent fireball frees its slot');
    }

    #[Test]
    public function aRefusedStepOntoThePlacedTileIsTriedOnceMore(): void
    {
        $calls = 0;
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(static function () use (&$calls): JsonResponse {
            ++$calls;

            return $calls === 1 ? new JsonResponse(['message' => 'refused'], 400) : new JsonResponse(['gameId' => 'x']);
        });
        $player = new class () {
            public function getSkills(): ?\App\Game\Player\HeroSkills
            {
                return null;
            }
        };
        $bus = MessageBusTester::create(static fn(\App\Game\Player\GetPlayer $_query): object => $player)->messageBus();
        $ai = new SmartVirtualPlayer($bus, new BasicVirtualPlayerStrategy(), new VirtualPlayerApiClient($kernel));

        $result = (new \ReflectionMethod(SmartVirtualPlayer::class, 'movePlayerSmart'))->invoke($ai, Uuid::v7(), Uuid::v7(), Uuid::v7(), 0, 0, 1, 0, true);

        self::assertSame(2, $calls);
        self::assertTrue($result['success']);
    }

    #[Test]
    public function theTurnCarriesItsCursedRoomRollsForThePlayback(): void
    {
        $turnId = Uuid::v7()->toString();
        $field = new class ($turnId) {
            public function __construct(private readonly string $turnId) {}

            /** @return list<array<string, mixed>> */
            public function getRoomEvents(): array
            {
                return [
                    ['turnId' => 'an-earlier-turn', 'position' => '0,1', 'roll' => 2],
                    ['turnId' => $this->turnId, 'position' => '1,1', 'roll' => 5, 'cursed' => false],
                ];
            }
        };
        $bus = MessageBusTester::create(static fn(\App\Game\Field\GetField $_query): object => $field)->messageBus();
        $ai = new SmartVirtualPlayer($bus, new BasicVirtualPlayerStrategy(), new VirtualPlayerApiClient($this->createMock(HttpKernelInterface::class)));
        $rooms = new \ReflectionMethod(SmartVirtualPlayer::class, 'roomEventsAction');

        $actions = $rooms->invoke($ai, Uuid::v7(), $turnId);

        self::assertSame('room_events', $actions[0]['type'] ?? null);
        self::assertSame(['1,1'], array_column($actions[0]['details']['events'], 'position'), 'Only the rolls of this turn');
        self::assertSame([], $rooms->invoke($ai, Uuid::v7(), null));
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
