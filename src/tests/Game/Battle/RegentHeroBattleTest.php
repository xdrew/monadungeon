<?php

declare(strict_types=1);

namespace App\Tests\Game\Battle;

use App\Game\Battle\Battle;
use App\Game\Battle\BattleCompleted;
use App\Game\Battle\BattleResult;
use App\Game\Battle\FinalizeBattle;
use App\Game\Battle\RetreatRequired;
use App\Game\Battle\StartBattle;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\Player;
use App\Game\Player\PlayerCursed;
use App\Game\Player\ReducePlayerHP;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Telephantast\MessageBus\MessageContext;
use function App\Tests\Infrastructure\MessageBus\handle;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Karak: Regent heroes in a fight: Valduk "Berserk", Lady Lorraine "Bear Attack", Baron Markul "Sword Master"
 * and the retreat after his "Lightning Attack".
 */
#[CoversClass(Battle::class)]
final class RegentHeroBattleTest extends TestCase
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
    public function berserkAddsOneAtThreeOrFourHp(): void
    {
        $this->field->setTestDiceRolls([2, 3]);
        $tester = $this->createTester($this->createPlayer(HeroType::BARBARIAN, 4));

        [, $messages] = $tester->handle(Battle::start(...), $this->startBattle(Item::createGiantRat())); // HP 5

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::WIN, $completed->result, '5 + 1 (Berserk) beats the giant rat');
        self::assertSame(1, $completed->berserkBonus);
        self::assertSame(6, $completed->totalDamage);
        self::assertSame(0, $completed->itemDamage, 'Berserk is not an item bonus');

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertSame(1, $fight->additionalData['berserk'] ?? null);
        self::assertSame('berserk', $fight->additionalData['heroAbility'] ?? null);
    }

    #[Test]
    public function berserkAddsTwoAtOneOrTwoHpAndSurvivesTheFinalization(): void
    {
        $this->field->setTestDiceRolls([1, 1]);
        $player = $this->createPlayer(HeroType::BARBARIAN, 2);
        $tester = $this->createTester($player);

        [$battle, $messages] = $tester->handle(Battle::start(...), $this->startBattle(Item::createGiantRat()));

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(BattleResult::LOSE, $completed->result, '2 + 2 is short of 5');
        self::assertSame(2, $completed->berserkBonus);
        self::assertSame(4, $completed->totalDamage);

        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(),
        );

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(2, $completed->berserkBonus);
        self::assertSame(4, $completed->totalDamage);
        self::assertSame(0, $completed->itemDamage);
    }

    #[Test]
    public function noBerserkAtFullHpOrWhileCursed(): void
    {
        $this->field->setTestDiceRolls([2, 2, 2, 2]);
        [, $messages] = $this->createTester($this->createPlayer(HeroType::BARBARIAN, 5))
            ->handle(Battle::start(...), $this->startBattle(Item::createGiantRat()));
        self::assertSame(0, $this->lastBattleCompleted($messages)->berserkBonus);

        $cursed = $this->createPlayer(HeroType::BARBARIAN, 1);
        $cursed->onPlayerCursed(new PlayerCursed($this->gameId, $this->playerId, Uuid::v7()));
        $this->battleId = Uuid::v7();
        [, $messages] = $this->createTester($cursed)->handle(Battle::start(...), $this->startBattle(Item::createGiantRat()));
        self::assertSame(0, $this->lastBattleCompleted($messages)->berserkBonus);
    }

    #[Test]
    public function bearAttackCountsAOneAsSix(): void
    {
        $this->field->setTestDiceRolls([1, 3]);
        [, $messages] = $this->createTester($this->createPlayer(HeroType::RANGER, 5))
            ->handle(Battle::start(...), $this->startBattle(Item::createGiantRat()));

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame([6, 3], $completed->diceResults);
        self::assertSame(1, $completed->onesAsSixes);
        self::assertSame(BattleResult::WIN, $completed->result);

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertSame('bear_attack', $fight->additionalData['heroAbility'] ?? null);
        self::assertSame(1, $fight->additionalData['onesAsSixes'] ?? null);
    }

    #[Test]
    public function aCursedRangerHasNoBear(): void
    {
        $this->field->setTestDiceRolls([1, 3]);
        $ranger = $this->createPlayer(HeroType::RANGER, 5);
        $ranger->onPlayerCursed(new PlayerCursed($this->gameId, $this->playerId, Uuid::v7()));

        [, $messages] = $this->createTester($ranger)->handle(Battle::start(...), $this->startBattle(Item::createGiantRat()));

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame([1, 3], $completed->diceResults);
        self::assertSame(0, $completed->onesAsSixes);
    }

    #[Test]
    public function markulFightsWithASwordOfThree(): void
    {
        $this->field->setTestDiceRolls([2, 1]);
        $markul = $this->createPlayer(HeroType::BATTLE_MAGE, 5);
        $this->giveWeapons($markul, [new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD, 9, 0, true)]);

        [, $messages] = $this->createTester($markul)->handle(Battle::start(...), $this->startBattle(Item::createSkeletonTurnkey())); // HP 8

        $completed = $this->lastBattleCompleted($messages);
        self::assertSame(3, $completed->itemDamage);
        self::assertSame(6, $completed->totalDamage);
    }

    #[Test]
    public function aLightningAttackThatIsNotWonWaitsForTheRetreat(): void
    {
        $this->field->setTestDiceRolls([1, 1]);
        $markul = $this->createPlayer(HeroType::BATTLE_MAGE, 5);
        $tester = $this->createTester($markul);

        [$battle] = $tester->handle(Battle::start(...), $this->startBattle(Item::createGiantRat(), lightning: true));
        [, $messages] = $tester->handle(
            static fn(FinalizeBattle $command, MessageContext $context) => $battle->finalizeBattle($command, $context),
            $this->finalize(),
        );

        self::assertSame(4, $markul->getHP(), 'A lost fight still costs 1 HP');
        $retreat = $this->findMessage($messages, RetreatRequired::class);
        self::assertInstanceOf(RetreatRequired::class, $retreat);
        self::assertSame('1,0', $retreat->position->toString());
        self::assertSame('0,0', $retreat->teleportedFrom->toString());
        self::assertNull($this->findMessage($messages, ResetPlayerPosition::class), 'He is not pushed back');
        self::assertNull($this->findMessage($messages, EndTurn::class), 'The turn ends after the retreat');

        $fight = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $fight);
        self::assertSame(TurnAction::FIGHT_MONSTER, $fight->action);
        self::assertTrue($fight->additionalData['lightningAttack'] ?? false);
    }

    #[Test]
    public function aWonLightningAttackIsAnOrdinaryVictory(): void
    {
        $this->field->setTestDiceRolls([6, 6]);
        [, $messages] = $this->createTester($this->createPlayer(HeroType::BATTLE_MAGE, 5))
            ->handle(Battle::start(...), $this->startBattle(Item::createGiantRat(), lightning: true));

        self::assertSame(BattleResult::WIN, $this->lastBattleCompleted($messages)->result);
        self::assertNull($this->findMessage($messages, RetreatRequired::class));
        self::assertNull($this->findMessage($messages, EndTurn::class));
    }

    private function startBattle(Item $monster, bool $lightning = false): StartBattle
    {
        return new StartBattle(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            monster: $monster,
            fromPosition: FieldPlace::fromString('0,0'),
            toPosition: FieldPlace::fromString('1,0'),
            lightning: $lightning,
        );
    }

    private function finalize(): FinalizeBattle
    {
        return new FinalizeBattle(
            battleId: $this->battleId,
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            selectedConsumableIds: [],
            pickupItem: false,
        );
    }

    private function createPlayer(HeroType $hero, int $hp): Player
    {
        [$player] = handle(Player::onPlayerAddedToGame(...), new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
            hp: $hp,
            hero: $hero,
        ));

        return $player;
    }

    /**
     * @param list<Item> $weapons
     */
    private function giveWeapons(Player $player, array $weapons): void
    {
        (new \ReflectionProperty(Player::class, 'inventory'))->setValue($player, [
            ItemCategory::KEY->value => [],
            ItemCategory::WEAPON->value => $weapons,
            ItemCategory::SPELL->value => [],
            ItemCategory::TREASURE->value => [],
        ]);
    }

    private function createTester(Player $player): MessageBusTester
    {
        $game = new class () {
            public function getStatus(): object
            {
                return new class () {
                    public function isFinished(): bool
                    {
                        return false;
                    }
                };
            }
        };

        return MessageBusTester::create(
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetPlayer $_query): Player => $player,
            fn(GetField $_query): Field => $this->field,
            static fn(GetGame $_query): object => $game,
            static function (ReducePlayerHP $command, MessageContext $context) use ($player): void {
                $player->reduceHP($command, $context);
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
