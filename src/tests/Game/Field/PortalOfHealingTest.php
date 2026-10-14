<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Player\PlayerHealedAtFountain;
use App\Game\Player\QueryPlayerInventory;
use App\Game\Player\RemoveItemFromInventory;
use App\Game\Player\ResetPlayerHP;
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

use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Portal of Healing: carries the caster or any player to a revealed fountain, heals at once, costs no move, keeps the turn.
 */
#[CoversClass(Field::class)]
final class PortalOfHealingTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $caster;

    private Uuid $other;

    private Uuid $turnId;

    private Field $field;

    private Item $portal;

    private FieldPlace $fountain;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->caster = Uuid::v7();
        $this->other = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
        $this->fountain = FieldPlace::fromString('2,0');
        $fountains = new \ReflectionProperty(Field::class, 'healingFountainPositions');
        $fountains->setValue($this->field, [FieldPlace::fromString('0,0'), $this->fountain]);
        // The giant spider's reward is the portal of healing
        $this->portal = Item::createGiantSpider()->defeatMonster();
    }

    #[Test]
    public function theCasterTeleportsAndKeepsTheTurn(): void
    {
        [, $messages] = $this->createTester()->handle($this->field->useSpell(...), $this->useSpell(targetPlayerId: null));

        $moved = $this->findMessage($messages, ResetPlayerPosition::class);
        self::assertInstanceOf(ResetPlayerPosition::class, $moved);
        self::assertTrue($moved->playerId->equals($this->caster));
        self::assertTrue($moved->position->equals($this->fountain));

        $healed = $this->findMessage($messages, ResetPlayerHP::class);
        self::assertInstanceOf(ResetPlayerHP::class, $healed);
        self::assertTrue($healed->playerId->equals($this->caster));
        self::assertFalse($healed->afterStun);

        $fountainEvent = $this->findMessage($messages, PlayerHealedAtFountain::class);
        self::assertInstanceOf(PlayerHealedAtFountain::class, $fountainEvent);
        self::assertTrue($fountainEvent->playerId->equals($this->caster));

        $removed = $this->findMessage($messages, RemoveItemFromInventory::class);
        self::assertInstanceOf(RemoveItemFromInventory::class, $removed);
        self::assertTrue($removed->playerId->equals($this->caster));
        self::assertTrue($removed->itemId->equals($this->portal->itemId));

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::USE_SPELL, $action->action);
        self::assertSame('teleport', $action->additionalData['spell'] ?? null);
        self::assertTrue($action->additionalData['self'] ?? false);
        self::assertSame('2,0', $action->additionalData['position'] ?? null);

        self::assertNull($this->findMessage($messages, EndTurn::class), 'The portal does not end the turn');
    }

    #[Test]
    public function anotherPlayerCanBeSentToTheFountain(): void
    {
        [, $messages] = $this->createTester()->handle($this->field->useSpell(...), $this->useSpell(targetPlayerId: $this->other));

        foreach ([ResetPlayerPosition::class, ResetPlayerHP::class, PlayerHealedAtFountain::class] as $class) {
            $message = $this->findMessage($messages, $class);
            self::assertInstanceOf($class, $message);
            self::assertTrue($message->playerId->equals($this->other), $class . ' targets the other player');
        }

        $removed = $this->findMessage($messages, RemoveItemFromInventory::class);
        self::assertInstanceOf(RemoveItemFromInventory::class, $removed);
        self::assertTrue($removed->playerId->equals($this->caster), 'The spell leaves the caster\'s inventory');

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertFalse($action->additionalData['self'] ?? true);
        self::assertSame($this->other->toString(), $action->additionalData['target'] ?? null);
        self::assertNull($this->findMessage($messages, EndTurn::class));
    }

    #[Test]
    public function theTargetMustBeAPlayerOfThisGame(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not in this game');
        $this->createTester()->handle($this->field->useSpell(...), $this->useSpell(targetPlayerId: Uuid::v7()));
    }

    #[Test]
    public function theDestinationMustBeAFountain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('healing fountain');
        $this->createTester()->handle($this->field->useSpell(...), $this->useSpell(targetPlayerId: null, position: FieldPlace::fromString('1,0')));
    }

    private function useSpell(?Uuid $targetPlayerId, ?FieldPlace $position = null): UseSpell
    {
        return new UseSpell(
            gameId: $this->gameId,
            playerId: $this->caster,
            turnId: $this->turnId,
            spellId: $this->portal->itemId,
            targetPosition: $position ?? $this->fountain,
            targetPlayerId: $targetPlayerId,
        );
    }

    private function createTester(): MessageBusTester
    {
        $players = [$this->caster, $this->other];
        $portal = $this->portal;

        return MessageBusTester::create(
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            fn(GetCurrentPlayer $_query): Uuid => $this->caster,
            static fn(QueryPlayerInventory $_query): array => ['spell' => [$portal->toArray()]],
            static fn(GetGame $_query): object => new class ($players) {
                /** @param list<Uuid> $players */
                public function __construct(private readonly array $players) {}

                /** @return list<Uuid> */
                public function getPlayers(): array
                {
                    return $this->players;
                }
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
