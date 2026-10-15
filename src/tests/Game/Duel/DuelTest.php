<?php

declare(strict_types=1);

namespace App\Tests\Game\Duel;

use App\Game\Battle\FinalizeBattle;
use App\Game\Battle\StartBattle;
use App\Game\Duel\BeginDuel;
use App\Game\Duel\Duel;
use App\Game\Duel\DuelLootTaken;
use App\Game\Duel\DuelPhase;
use App\Game\Duel\DuelResolved;
use App\Game\Duel\DuelSide;
use App\Game\Duel\DuelSideCompleted;
use App\Game\Duel\DuelStarted;
use App\Game\Duel\SettleDuel;
use App\Game\Duel\StartDuel;
use App\Game\Duel\TakeDuelLoot;
use App\Game\Field\AutoReincarnate;
use App\Game\Field\FieldPlace;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Item\Item;
use App\Game\Item\ItemType;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\AddItemToInventory;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\Player;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GameTurn;
use App\Game\Turn\GetTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\StartTurn;
use App\Game\Turn\TurnAction;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Telephantast\MessageBus\MessageContext;
use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Karak: Regent — the Duel aggregate: two Battles in duel mode, compared, the loser loses 1 HP, the winner takes one item.
 */
#[CoversClass(Duel::class)]
final class DuelTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $challenger;

    private Uuid $opponent;

    private Uuid $turnId;

    /** @var array<string, Player> */
    private array $players = [];

    private GameTurn $turn;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->challenger = Uuid::v7();
        $this->opponent = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->players = [];
        $this->addPlayer($this->challenger);
        $this->addPlayer($this->opponent);
        [$this->turn] = handle(GameTurn::start(...), new StartTurn($this->gameId, $this->challenger, 1, $this->turnId, new \DateTimeImmutable()));
    }

    /**
     * @return iterable<string, array{int, int, bool, bool, string}>
     */
    public static function results(): iterable
    {
        yield 'higher opponent' => [9, 10, false, false, Duel::RESULT_OPPONENT];
        yield 'higher challenger' => [9, 8, false, false, Duel::RESULT_CHALLENGER];
        yield 'equal, nobody wins ties' => [9, 9, false, false, Duel::RESULT_TIE];
        yield 'equal, the opponent wins ties' => [9, 9, false, true, Duel::RESULT_OPPONENT];
        yield 'equal, the challenger wins ties' => [9, 9, true, false, Duel::RESULT_CHALLENGER];
        yield 'equal, both win ties' => [9, 9, true, true, Duel::RESULT_TIE];
    }

    #[Test]
    public function theChallengerRollsFirst(): void
    {
        $this->addPlayer($this->challenger, hero: HeroType::ORACLE);
        $tester = $this->createTester();

        [$duel, $messages] = $tester->handle(Duel::start(...), $this->startDuel(firstStep: true));
        $started = $this->findMessage($messages, DuelStarted::class);
        self::assertInstanceOf(DuelStarted::class, $started);
        self::assertNull($this->findMessage($messages, StartBattle::class), 'The battle starts only once the duel exists');

        [, $messages] = $tester->handle($duel->begin(...), new BeginDuel($duel->getDuelId(), $this->gameId));
        $battle = $this->findMessage($messages, StartBattle::class);
        self::assertInstanceOf(StartBattle::class, $battle);
        self::assertTrue($battle->playerId->equals($this->challenger));
        self::assertTrue($battle->battleId->equals($duel->getChallengerBattleId()));
        self::assertSame(0, $battle->monster->guardHP);
        self::assertNotNull($battle->duel);
        self::assertSame(DuelSide::CHALLENGER, $battle->duel->role);
        self::assertNull($battle->duel->toBeat);
        self::assertFalse($battle->duel->auto);
        self::assertTrue($battle->duel->farseeing, 'The arena was the Oracle\'s first step');
        self::assertTrue($battle->duel->opponentId->equals($this->opponent));

        [, $messages] = $tester->handle($duel->begin(...), new BeginDuel($duel->getDuelId(), $this->gameId));
        self::assertSame([], $messages, 'The duel begins once');
    }

    #[Test]
    public function theOpponentRollsAgainstTheChallengersTotal(): void
    {
        $this->addPlayer($this->opponent, hero: HeroType::ORACLE);
        $tester = $this->createTester();
        $duel = $this->begunDuel($tester);

        [, $messages] = $tester->handle($duel->onDuelSideCompleted(...), $this->side($duel, DuelSide::CHALLENGER, 9));

        self::assertSame(DuelPhase::OPPONENT_ROLL, $duel->getPhase());
        $battle = $this->findMessage($messages, StartBattle::class);
        self::assertInstanceOf(StartBattle::class, $battle);
        self::assertTrue($battle->playerId->equals($this->opponent));
        self::assertTrue($battle->battleId->equals($duel->getOpponentBattleId()));
        self::assertSame(9, $battle->monster->guardHP);
        self::assertNotNull($battle->duel);
        self::assertSame(DuelSide::OPPONENT, $battle->duel->role);
        self::assertSame(9, $battle->duel->toBeat);
        self::assertFalse($battle->duel->auto);
        self::assertTrue($battle->duel->farseeing, 'A summoned Oracle always gets +1');
        self::assertTrue($battle->duel->opponentId->equals($this->challenger));
    }

    #[Test]
    public function aHumanSummonedByAVirtualPlayerIsRolledAutomatically(): void
    {
        $this->addPlayer($this->challenger, isAi: true);
        $tester = $this->createTester();
        $duel = $this->begunDuel($tester);

        [, $messages] = $tester->handle($duel->onDuelSideCompleted(...), $this->side($duel, DuelSide::CHALLENGER, 7));

        $battle = $this->findMessage($messages, StartBattle::class);
        self::assertInstanceOf(StartBattle::class, $battle);
        self::assertTrue($battle->duel?->auto ?? false);
    }

    #[Test]
    #[DataProvider('results')]
    public function theTotalsDecide(int $challengerTotal, int $opponentTotal, bool $challengerTies, bool $opponentTies, string $expected): void
    {
        [$duel, $messages] = $this->resolvedDuel($challengerTotal, $opponentTotal, $challengerTies, $opponentTies);

        self::assertSame($expected, $duel->getResult());
        $challengerHp = $this->players[$this->challenger->toString()]->getHP();
        $opponentHp = $this->players[$this->opponent->toString()]->getHP();
        if ($expected === Duel::RESULT_TIE) {
            self::assertSame([5, 5], [$challengerHp, $opponentHp], 'A tie: nothing happens');
            self::assertSame(DuelPhase::DONE, $duel->getPhase());
        } else {
            self::assertSame(
                $expected === Duel::RESULT_CHALLENGER ? [5, 4] : [4, 5],
                [$challengerHp, $opponentHp],
                'The loser loses 1 HP',
            );
        }

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::DUEL, $action->action);
        self::assertTrue($action->playerId->equals($this->challenger));
        self::assertSame($challengerTotal, $action->additionalData['challengerTotal'] ?? null);
        self::assertSame($opponentTotal, $action->additionalData['opponentTotal'] ?? null);
        self::assertSame($expected, $action->additionalData['result'] ?? null);
        self::assertNull($this->findMessage($messages, EndTurn::class), 'The duel does not end the turn');
    }

    #[Test]
    public function aKnockedOutWarriorReincarnatesAtOnce(): void
    {
        $this->addPlayer($this->opponent, hero: HeroType::WARRIOR, hp: 1);
        [, $messages] = $this->resolvedDuel(10, 5);

        $reincarnate = $this->findMessage($messages, AutoReincarnate::class);
        self::assertInstanceOf(AutoReincarnate::class, $reincarnate);
        self::assertTrue($reincarnate->playerId->equals($this->opponent));
        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertSame('0,0', $action?->additionalData['reincarnatedTo'] ?? null);
    }

    #[Test]
    public function aVirtualWinnerTakesTheBestItemItHasRoomFor(): void
    {
        $this->addPlayer($this->opponent, isAi: true, inventory: ['skeleton_warrior', 'skeleton_warrior']);
        $this->addPlayer($this->challenger, inventory: ['skeleton_king', 'mummy', 'skeleton_mage']);

        [$duel, $messages] = $this->resolvedDuel(5, 10);

        $taken = $this->findMessage($messages, DuelLootTaken::class);
        self::assertInstanceOf(DuelLootTaken::class, $taken);
        self::assertSame(ItemType::FROST_FIST, $taken->item->type, 'No room for the axe: the Frost Fist beats the fireball');
        $this->assertTransferred($messages, $taken->item, from: $this->challenger, to: $this->opponent);
        self::assertSame(DuelPhase::DONE, $duel->getPhase());
        self::assertSame('frost_fist', $duel->getLoot()['type'] ?? null);

        $taken = $this->findMessage($messages, DuelLootTaken::class);
        $loserIndex = array_search($taken, $messages, true);
        $removeIndex = array_search($this->findMessage($messages, RemoveItemFromInventory::class), $messages, true);
        self::assertLessThan($removeIndex, $loserIndex, 'The Field learns about the loot before the item leaves the inventory');
    }

    #[Test]
    public function lootPriority(): void
    {
        $chest = Duel::lootScore(Item::createTreasureChest());
        $axe = Duel::lootScore(Item::createSkeletonKing());
        $sword = Duel::lootScore(Item::createSkeletonWarrior());
        $frostFist = Duel::lootScore(Item::createSkeletonMage());
        $fireball = Duel::lootScore(Item::createMummy());
        $darkThorn = Duel::lootScore(Item::createGiantBat());
        $teleport = Duel::lootScore(Item::createGiantSpider());
        $key = Duel::lootScore(Item::createSkeletonTurnkey());

        self::assertGreaterThan($axe, $chest);
        self::assertGreaterThan($sword, $axe);
        self::assertGreaterThan($frostFist, $sword);
        self::assertGreaterThan($fireball, $frostFist);
        self::assertGreaterThan($darkThorn, $fireball);
        self::assertGreaterThan($teleport, $darkThorn);
        self::assertGreaterThan($key, $teleport);
    }

    #[Test]
    public function aHumanChallengerPicksTheLoot(): void
    {
        $this->addPlayer($this->opponent, inventory: ['treasure_chest', 'skeleton_king']);
        [$duel, $messages] = $this->resolvedDuel(10, 5);

        self::assertSame(DuelPhase::LOOT, $duel->getPhase());
        self::assertNull($this->findMessage($messages, DuelLootTaken::class));
        $resolved = $this->findMessage($messages, DuelResolved::class);
        self::assertInstanceOf(DuelResolved::class, $resolved);
        self::assertSame('loot', $resolved->phase);
        self::assertCount(2, $resolved->lootCandidates);

        $axe = $this->opponentItem(ItemType::AXE);
        $tester = $this->createTester();
        $this->assertLootFails($tester, $duel, $this->opponent, $axe->itemId, 'Only the winner');
        $this->assertLootFails($tester, $duel, $this->challenger, Uuid::v7(), 'Unknown item');

        [, $messages] = $tester->handle($duel->takeLoot(...), new TakeDuelLoot($this->gameId, $this->challenger, $this->turnId, $duel->getDuelId(), $axe->itemId));
        $taken = $this->findMessage($messages, DuelLootTaken::class);
        self::assertInstanceOf(DuelLootTaken::class, $taken);
        self::assertTrue($taken->item->itemId->equals($axe->itemId));
        $this->assertTransferred($messages, $axe, from: $this->opponent, to: $this->challenger);
        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::DUEL_LOOT, $action->action);
        self::assertSame('axe', $action->additionalData['item'] ?? null);
        self::assertSame(DuelPhase::DONE, $duel->getPhase());

        $this->assertLootFails($tester, $duel, $this->challenger, $axe->itemId, 'No loot to take');
    }

    #[Test]
    public function theWinnerNeedsRoomForTheChosenItem(): void
    {
        $this->addPlayer($this->opponent, inventory: ['mummy']);
        [$duel] = $this->resolvedDuel(10, 5);
        self::assertSame(DuelPhase::LOOT, $duel->getPhase());

        // The challenger's spell slots filled up meanwhile
        $this->players[$this->challenger->toString()]->setTestInventory(['mummy', 'giant_bat', 'giant_spider']);
        $this->assertLootFails($this->createTester(), $duel, $this->challenger, $this->opponentItem(ItemType::FIREBALL)->itemId, 'No room');
    }

    #[Test]
    public function nothingToTakeEndsTheDuel(): void
    {
        [$duel, $messages] = $this->resolvedDuel(10, 5);

        self::assertSame(DuelPhase::DONE, $duel->getPhase());
        self::assertNull($this->findMessage($messages, DuelLootTaken::class));
        self::assertNull($duel->getLoot());
    }

    #[Test]
    public function aKnockedOutChallengerEndsHisTurn(): void
    {
        $this->addPlayer($this->challenger, hp: 1);
        [, $messages] = $this->resolvedDuel(5, 10);

        $end = $this->findMessage($messages, EndTurn::class);
        self::assertInstanceOf(EndTurn::class, $end);
        self::assertTrue($end->playerId->equals($this->challenger));
    }

    #[Test]
    public function theTurnEndSettlesAPendingRollAndAPendingLoot(): void
    {
        $tester = $this->createTester();
        $duel = $this->begunDuel($tester);
        $tester->handle($duel->onDuelSideCompleted(...), $this->side($duel, DuelSide::CHALLENGER, 9));

        [, $messages] = $tester->handle($duel->settle(...), new SettleDuel($duel->getDuelId(), $this->gameId));
        $finalize = $this->findMessage($messages, FinalizeBattle::class);
        self::assertInstanceOf(FinalizeBattle::class, $finalize);
        self::assertTrue($finalize->battleId->equals($duel->getOpponentBattleId()));
        self::assertTrue($finalize->playerId->equals($this->opponent));
        self::assertSame([], $finalize->selectedConsumableIds);

        $this->addPlayer($this->opponent, inventory: ['treasure_chest', 'skeleton_king']);
        [$duel] = $this->resolvedDuel(10, 5);
        self::assertSame(DuelPhase::LOOT, $duel->getPhase());
        [, $messages] = $this->createTester()->handle($duel->settle(...), new SettleDuel($duel->getDuelId(), $this->gameId));
        $taken = $this->findMessage($messages, DuelLootTaken::class);
        self::assertInstanceOf(DuelLootTaken::class, $taken);
        self::assertSame(ItemType::CHEST, $taken->item->type);
        self::assertSame(DuelPhase::DONE, $duel->getPhase());
    }

    /**
     * @return array{Duel, list<object>}
     */
    private function resolvedDuel(int $challengerTotal, int $opponentTotal, bool $challengerTies = false, bool $opponentTies = false): array
    {
        $tester = $this->createTester();
        $duel = $this->begunDuel($tester);
        $tester->handle($duel->onDuelSideCompleted(...), $this->side($duel, DuelSide::CHALLENGER, $challengerTotal, $challengerTies));
        [, $messages] = $tester->handle($duel->onDuelSideCompleted(...), $this->side($duel, DuelSide::OPPONENT, $opponentTotal, $opponentTies));

        return [$duel, $messages];
    }

    private function begunDuel(MessageBusTester $tester): Duel
    {
        [$duel] = $tester->handle(Duel::start(...), $this->startDuel(firstStep: false));
        $tester->handle($duel->begin(...), new BeginDuel($duel->getDuelId(), $this->gameId));

        return $duel;
    }

    private function startDuel(bool $firstStep): StartDuel
    {
        return new StartDuel(
            duelId: Uuid::v7(),
            gameId: $this->gameId,
            turnId: $this->turnId,
            arenaPosition: FieldPlace::fromString('1,0'),
            challengerId: $this->challenger,
            opponentId: $this->opponent,
            firstStep: $firstStep,
        );
    }

    private function side(Duel $duel, string $role, int $total, bool $tieIsWin = false): DuelSideCompleted
    {
        $isChallenger = $role === DuelSide::CHALLENGER;

        return new DuelSideCompleted(
            duelId: $duel->getDuelId(),
            gameId: $this->gameId,
            battleId: $isChallenger ? $duel->getChallengerBattleId() : $duel->getOpponentBattleId(),
            role: $role,
            playerId: $isChallenger ? $this->challenger : $this->opponent,
            total: $total,
            diceResults: [3, 3],
            itemDamage: $total - 6,
            usedItems: [],
            tieIsWin: $tieIsWin,
        );
    }

    /**
     * @param list<string> $inventory
     */
    private function addPlayer(Uuid $playerId, bool $isAi = false, ?HeroType $hero = null, int $hp = 5, array $inventory = []): void
    {
        $player = Player::onPlayerAddedToGame(new PlayerAdded($this->gameId, $playerId, isAi: $isAi, hero: $hero));
        $player->setTestInventory($inventory);
        $player->setTestHp($hp);
        $this->players[$playerId->toString()] = $player;
    }

    private function opponentItem(ItemType $type): Item
    {
        foreach ($this->players[$this->opponent->toString()]->getInventory() as $items) {
            foreach ($items as $item) {
                $item = Item::fromAnything($item);
                if ($item->type === $type) {
                    return $item;
                }
            }
        }
        self::fail('The opponent has no ' . $type->value);
    }

    /**
     * @param list<object> $messages
     */
    private function assertTransferred(array $messages, Item $item, Uuid $from, Uuid $to): void
    {
        $removed = $this->findMessage($messages, RemoveItemFromInventory::class);
        self::assertInstanceOf(RemoveItemFromInventory::class, $removed);
        self::assertTrue($removed->playerId->equals($from));
        self::assertTrue($removed->itemId->equals($item->itemId));
        $added = $this->findMessage($messages, AddItemToInventory::class);
        self::assertInstanceOf(AddItemToInventory::class, $added);
        self::assertTrue($added->playerId->equals($to));
        self::assertTrue($added->item->itemId->equals($item->itemId));
    }

    private function assertLootFails(MessageBusTester $tester, Duel $duel, Uuid $playerId, Uuid $itemId, string $message): void
    {
        try {
            $tester->handle($duel->takeLoot(...), new TakeDuelLoot($this->gameId, $playerId, $this->turnId, $duel->getDuelId(), $itemId));
            self::fail("Expected a DomainException containing '{$message}'");
        } catch (\DomainException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function createTester(): MessageBusTester
    {
        $players = &$this->players;
        $turn = $this->turn;

        return MessageBusTester::create(
            static function (GetPlayer $query) use (&$players): Player {
                return $players[$query->playerId->toString()];
            },
            static function (ReducePlayerHP $command, MessageContext $context) use (&$players): void {
                $players[$command->playerId->toString()]->reduceHP($command, $context);
            },
            fn(GetCurrentPlayer $_query): Uuid => $this->challenger,
            static fn(GetTurn $_query): GameTurn => $turn,
            static fn(GetPlayerPosition $_query): FieldPlace => FieldPlace::fromString('0,0'),
        );
    }

    /**
     * @template T of object
     * @param list<object> $messages
     * @param class-string<T> $class
     * @return T|null
     */
    private function findMessage(array $messages, string $class): ?object
    {
        foreach ($messages as $message) {
            if ($message instanceof $class) {
                return $message;
            }
        }

        return null;
    }
}
