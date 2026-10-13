<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\Reincarnate;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Player\CompleteReincarnation;
use App\Game\Player\GetPlayer;
use App\Game\Player\ReincarnationRequired;
use App\Game\Turn\EndTurn;
use App\Game\Turn\Error\NotYourTurnException;
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
 * Warrior "Reincarnation": teleport to a chosen healing fountain, replenish HP, end the turn.
 */
#[CoversClass(Field::class)]
final class ReincarnationTest extends TestCase
{
    private Uuid $gameId;
    private Uuid $playerId;
    private Uuid $turnId;
    private Field $field;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());
    }

    #[Test]
    public function reincarnationRequiredExposesHealingFountainsInLastBattleInfo(): void
    {
        $this->field->onReincarnationRequired(new ReincarnationRequired($this->gameId, $this->playerId, $this->turnId));

        $info = $this->field->getLastBattleInfo();
        self::assertNotNull($info);
        self::assertTrue($info['awaitingReincarnation']);
        self::assertContains('0,0', $info['healingFountainPositions'], 'The starting tile is always a healing fountain');
    }

    #[Test]
    public function heroTeleportsToFountainReplenishesHpAndEndsTurn(): void
    {
        $tester = $this->createTester(awaitingReincarnation: true);

        [, $messages] = $tester->handle(
            fn(Reincarnate $command, MessageContext $context) => $this->field->reincarnate($command, $context),
            $this->reincarnate('0,0'),
        );

        // Queries have handlers and are not recorded; the recorded messages are the dispatched commands in order
        self::assertSame(
            [ResetPlayerPosition::class, CompleteReincarnation::class, PerformTurnAction::class, EndTurn::class],
            array_map(static fn(object $message): string => $message::class, $messages),
            'Teleport, replenish HP, record the ability, then end the turn',
        );

        $reset = $this->findMessage($messages, ResetPlayerPosition::class);
        self::assertInstanceOf(ResetPlayerPosition::class, $reset);
        self::assertSame('0,0', $reset->position->toString());

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::USE_HERO_ABILITY, $action->action);
        self::assertSame('reincarnation', $action->additionalData['ability'] ?? null);

        $endTurn = $this->findMessage($messages, EndTurn::class);
        self::assertInstanceOf(EndTurn::class, $endTurn);
        self::assertTrue($endTurn->turnId->equals($this->turnId));
    }

    #[Test]
    public function targetMustBeAHealingFountain(): void
    {
        $tester = $this->createTester(awaitingReincarnation: true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('healing fountain');
        $tester->handle(
            fn(Reincarnate $command, MessageContext $context) => $this->field->reincarnate($command, $context),
            $this->reincarnate('3,3'),
        );
    }

    #[Test]
    public function onlyAFallenReincarnatingHeroCanReincarnate(): void
    {
        $tester = $this->createTester(awaitingReincarnation: false);

        $this->expectException(\DomainException::class);
        $tester->handle(
            fn(Reincarnate $command, MessageContext $context) => $this->field->reincarnate($command, $context),
            $this->reincarnate('0,0'),
        );
    }

    #[Test]
    public function otherPlayerCannotReincarnateDuringMyTurn(): void
    {
        $tester = $this->createTester(awaitingReincarnation: true, currentPlayerId: Uuid::v7());

        $this->expectException(NotYourTurnException::class);
        $tester->handle(
            fn(Reincarnate $command, MessageContext $context) => $this->field->reincarnate($command, $context),
            $this->reincarnate('0,0'),
        );
    }

    private function reincarnate(string $target): Reincarnate
    {
        return new Reincarnate(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            targetPosition: FieldPlace::fromString($target),
        );
    }

    private function createTester(bool $awaitingReincarnation, ?Uuid $currentPlayerId = null): MessageBusTester
    {
        $player = new class($awaitingReincarnation) {
            public function __construct(private readonly bool $awaiting) {}

            public function isAwaitingReincarnation(): bool
            {
                return $this->awaiting;
            }
        };
        $currentPlayerId ??= $this->playerId;

        return MessageBusTester::create(
            static fn(GetCurrentPlayer $_query): Uuid => $currentPlayerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            static fn(GetPlayer $_query): object => $player,
        );
    }

    /**
     * @param list<object> $messages
     * @param class-string $class
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
