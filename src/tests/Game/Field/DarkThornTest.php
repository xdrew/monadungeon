<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Field\AutoReincarnate;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Item\Item;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\CompleteReincarnation;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\Player;
use App\Game\Player\QueryPlayerInventory;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Player\UseSpell;
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
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Karak: Regent — Dark Thorn: on your turn another hero loses 1 HP; costs no move, several per turn.
 */
#[CoversClass(Field::class)]
final class DarkThornTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $caster;

    private Uuid $target;

    private Uuid $turnId;

    private Field $field;

    /** @var array<string, Player> */
    private array $players = [];

    /** @var array<string, FieldPlace> */
    private array $positions = [];

    private Item $spell;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->caster = Uuid::v7();
        $this->target = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
        (new \ReflectionProperty(Field::class, 'healingFountainPositions'))->setValue($this->field, [FieldPlace::fromString('0,0'), FieldPlace::fromString('3,0')]);
        $this->spell = Item::createGiantBat()->defeatMonster();
        $this->players = [
            $this->caster->toString() => Player::onPlayerAddedToGame(new PlayerAdded($this->gameId, $this->caster)),
            $this->target->toString() => Player::onPlayerAddedToGame(new PlayerAdded($this->gameId, $this->target)),
        ];
        $this->positions = [$this->target->toString() => FieldPlace::fromString('2,0')];
    }

    #[Test]
    public function anotherHeroLosesOneHpAndTheTurnGoesOn(): void
    {
        [, $messages] = $this->createTester()->handle($this->field->useSpell(...), $this->cast($this->target));

        self::assertSame(4, $this->players[$this->target->toString()]->getHP());
        $removed = $this->findMessage($messages, RemoveItemFromInventory::class);
        self::assertInstanceOf(RemoveItemFromInventory::class, $removed);
        self::assertTrue($removed->playerId->equals($this->caster));
        self::assertTrue($removed->itemId->equals($this->spell->itemId));

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::USE_SPELL, $action->action);
        self::assertSame(
            ['spell' => 'dark_thorn', 'target' => $this->target->toString(), 'hpBefore' => 5, 'hpAfter' => 4, 'stunned' => false, 'reincarnatedTo' => null],
            $action->additionalData,
        );
        self::assertNull($this->findMessage($messages, EndTurn::class), 'Dark Thorn costs no move and does not end the turn');
    }

    #[Test]
    public function theLastHpStunsTheTarget(): void
    {
        $this->players[$this->target->toString()]->setTestHp(1);
        [, $messages] = $this->createTester()->handle($this->field->useSpell(...), $this->cast($this->target));

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertTrue($action?->additionalData['stunned'] ?? false);
        self::assertSame(0, $this->players[$this->target->toString()]->getHP());
    }

    #[Test]
    public function aWarriorReincarnatesAtTheNearestFountain(): void
    {
        $warrior = Player::onPlayerAddedToGame(new PlayerAdded($this->gameId, $this->target, hero: HeroType::WARRIOR));
        $warrior->setTestHp(1);
        $this->players[$this->target->toString()] = $warrior;

        [, $messages] = $this->createTester()->handle($this->field->useSpell(...), $this->cast($this->target));

        self::assertSame('3,0', $this->positions[$this->target->toString()]->toString(), '3,0 is nearer to 2,0 than the start');
        self::assertFalse($warrior->isAwaitingReincarnation());
        self::assertSame(5, $warrior->getHP());
        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertSame('3,0', $action?->additionalData['reincarnatedTo'] ?? null);
        self::assertFalse($action?->additionalData['stunned'] ?? true);
    }

    #[Test]
    public function autoReincarnationIgnoresAHeroWhoIsNotFallen(): void
    {
        $this->createTester()->handle($this->field->autoReincarnate(...), new AutoReincarnate($this->gameId, $this->target));

        self::assertSame('2,0', $this->positions[$this->target->toString()]->toString());
    }

    #[Test]
    public function theTargetMustBeAnotherPlayerOfThisGame(): void
    {
        $this->assertCastFails($this->cast(null), 'needs a target');
        $this->assertCastFails($this->cast($this->caster), 'cannot target yourself');
        $this->assertCastFails($this->cast(Uuid::v7()), 'not in this game');
    }

    #[Test]
    public function frostFistIsNotCastFromTheSidebar(): void
    {
        $this->spell = Item::createSkeletonMage()->defeatMonster();
        $this->assertCastFails($this->cast($this->target), 'used during a fight');
    }

    private function cast(?Uuid $target): UseSpell
    {
        return new UseSpell(
            gameId: $this->gameId,
            playerId: $this->caster,
            turnId: $this->turnId,
            spellId: $this->spell->itemId,
            targetPlayerId: $target,
        );
    }

    private function assertCastFails(UseSpell $command, string $message): void
    {
        try {
            $this->createTester()->handle($this->field->useSpell(...), $command);
            self::fail("Expected an InvalidArgumentException containing '{$message}'");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function createTester(): MessageBusTester
    {
        $players = &$this->players;
        $positions = &$this->positions;
        $playerIds = [$this->caster, $this->target];
        $spell = $this->spell;

        return MessageBusTester::create(
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            fn(GetCurrentPlayer $_query): Uuid => $this->caster,
            static fn(QueryPlayerInventory $_query): array => ['spell' => [$spell->toArray()]],
            static fn(GetGame $_query): object => new class ($playerIds) {
                /** @param list<Uuid> $players */
                public function __construct(private readonly array $players) {}

                /** @return list<Uuid> */
                public function getPlayers(): array
                {
                    return $this->players;
                }
            },
            static function (GetPlayer $query) use (&$players): Player {
                return $players[$query->playerId->toString()];
            },
            static function (ReducePlayerHP $command, MessageContext $context) use (&$players): void {
                $players[$command->playerId->toString()]->reduceHP($command, $context);
            },
            static function (CompleteReincarnation $command, MessageContext $context) use (&$players): void {
                $players[$command->playerId->toString()]->completeReincarnation($command, $context);
            },
            static function (GetPlayerPosition $query) use (&$positions): FieldPlace {
                return $positions[$query->playerId->toString()];
            },
            static function (ResetPlayerPosition $command) use (&$positions): void {
                $positions[$command->playerId->toString()] = $command->position;
            },
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
