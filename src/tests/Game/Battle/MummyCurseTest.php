<?php

declare(strict_types=1);

namespace App\Tests\Game\Battle;

use App\Game\Battle\Battle;
use App\Game\Battle\BattleCompleted;
use App\Game\Battle\BattleResult;
use App\Game\Battle\MummyDefeated;
use App\Game\Battle\StartBattle;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Player\GetActivePlayers;
use App\Game\Player\GetPlayer;
use App\Game\Player\ReducePlayerHP;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Telephantast\MessageBus\MessageContext;

use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Curse of Karak: beating a mummy offers the curse to a human slayer in a game with other players.
 */
#[CoversClass(Battle::class)]
final class MummyCurseTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    private Uuid $battleId;

    private Field $field;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->battleId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
    }

    #[Test]
    public function aHumanSlayerIsAskedWhomToCurse(): void
    {
        $this->field->setTestDiceRolls([4, 4]);
        $tester = $this->createTester(players: 2, isAi: false, holder: null);

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createMummy()), // HP 7
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result);
        self::assertTrue($completed->curseChoiceRequired);
        self::assertNull($completed->cursedPlayerId);

        $defeated = array_values(array_filter($messages, static fn(object $m): bool => $m instanceof MummyDefeated));
        self::assertCount(1, $defeated, 'MummyDefeated is dispatched exactly once');
        self::assertTrue($defeated[0]->playerId->equals($this->playerId));
        self::assertTrue($defeated[0]->turnId->equals($this->turnId));
    }

    #[Test]
    public function otherMonstersDoNotCurse(): void
    {
        $this->field->setTestDiceRolls([4, 4]);
        $tester = $this->createTester(players: 2, isAi: false, holder: null);

        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createGiantRat()),
        );

        self::assertSame(BattleResult::WIN, $this->lastBattleCompleted($messages)->result);
        self::assertFalse($this->lastBattleCompleted($messages)->curseChoiceRequired);
        self::assertNull($this->findMessage($messages, MummyDefeated::class));
    }

    #[Test]
    public function noChoiceForALonelyOrVirtualSlayer(): void
    {
        $this->field->setTestDiceRolls([4, 4]);
        $tester = $this->createTester(players: 1, isAi: false, holder: null);
        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createMummy()),
        );
        self::assertFalse($this->lastBattleCompleted($messages)->curseChoiceRequired);
        self::assertInstanceOf(MummyDefeated::class, $this->findMessage($messages, MummyDefeated::class), 'The Game decides, the event is still sent');

        $this->field->setTestDiceRolls([4, 4]);
        $tester = $this->createTester(players: 3, isAi: true, holder: null);
        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createMummy()),
        );
        self::assertFalse($this->lastBattleCompleted($messages)->curseChoiceRequired, 'A virtual slayer is handled by the Game');
        self::assertInstanceOf(MummyDefeated::class, $this->findMessage($messages, MummyDefeated::class));
    }

    #[Test]
    public function theCurrentHolderIsReportedAndALossChangesNothing(): void
    {
        $holder = Uuid::v7();
        $this->field->setTestDiceRolls([4, 4]);
        $tester = $this->createTester(players: 2, isAi: false, holder: $holder);
        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createMummy()),
        );
        self::assertTrue($this->lastBattleCompleted($messages)->curseChoiceRequired);
        self::assertSame($holder->toString(), $this->lastBattleCompleted($messages)->cursedPlayerId);

        $this->field->setTestDiceRolls([1, 2]);
        $tester = $this->createTester(players: 2, isAi: false, holder: $holder);
        [, $messages] = $tester->handle(
            static fn(StartBattle $command, MessageContext $context): Battle => Battle::start($command, $context),
            $this->startBattle(Item::createMummy()),
        );
        self::assertSame(BattleResult::LOSE, $this->lastBattleCompleted($messages)->result);
        self::assertFalse($this->lastBattleCompleted($messages)->curseChoiceRequired);
        self::assertNull($this->findMessage($messages, MummyDefeated::class));
    }

    private function startBattle(Item $monster): StartBattle
    {
        return new StartBattle(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            monster: $monster,
            fromPosition: FieldPlace::fromString('0,0'),
            toPosition: FieldPlace::fromString('1,0'),
        );
    }

    private function createTester(int $players, bool $isAi, ?Uuid $holder): MessageBusTester
    {
        $player = new class ($isAi) {
            public function __construct(private readonly bool $isAi) {}

            public function getHero(): ?object
            {
                return null;
            }

            public function getSkills(): ?\App\Game\Player\HeroSkills
            {
                $hero = $this->getHero();

                return $hero instanceof \App\Game\Player\HeroType ? \App\Game\Player\HeroSkills::of($hero) : null;
            }

            public function getHP(): int
            {
                return 3;
            }

            public function isAi(): bool
            {
                return $this->isAi;
            }

            public function loseHP(int $amount): void {}

            public function isAwaitingReincarnation(): bool
            {
                return false;
            }

            /** @return list<Item> */
            public function getWeaponItems(): array
            {
                return [];
            }

            public function calculateWeaponDamage(): int
            {
                return 0;
            }

            /** @return list<Item> */
            public function getConsumableItems(): array
            {
                return [];
            }
        };
        $game = new class ($holder) {
            public function __construct(private readonly ?Uuid $holder) {}

            public function getStatus(): object
            {
                return new class () {
                    public function isFinished(): bool
                    {
                        return false;
                    }
                };
            }

            public function getCursedPlayerId(): ?Uuid
            {
                return $this->holder;
            }
        };
        $playerIds = [$this->playerId];
        for ($i = 1; $i < $players; ++$i) {
            $playerIds[] = Uuid::v7();
        }

        return MessageBusTester::create(
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetPlayer $_query): object => $player,
            static fn(GetActivePlayers $_query): array => $playerIds,
            fn(GetField $_query): Field => $this->field,
            static fn(GetGame $_query): object => $game,
            static function (ReducePlayerHP $command) use ($player): void {
                $player->loseHP($command->amount);
            },
        );
    }

    /**
     * @param list<object> $messages
     */
    private function lastBattleCompleted(array $messages): BattleCompleted
    {
        $completed = null;
        foreach ($messages as $message) {
            if ($message instanceof BattleCompleted) {
                $completed = $message;
            }
        }
        self::assertInstanceOf(BattleCompleted::class, $completed, 'Expected BattleCompleted event');

        return $completed;
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
