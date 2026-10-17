<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Battle\RetreatRequired;
use App\Game\Deck\DeckTile;
use App\Game\Field\ChooseRetreat;
use App\Game\Field\ContinueAfterVictory;
use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\Listen;
use App\Game\Field\Tile;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Game\Turn\TurnEnded;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Karak: Regent heroes on the Field: Valduk "Tireless", Baron Markul's retreat after a Lightning Attack
 * and Lady Lorraine "Listening".
 */
#[CoversClass(Field::class)]
final class RegentHeroFieldTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    private Field $field;

    private ?HeroType $hero = null;

    private FieldPlace $position;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->position = FieldPlace::fromString('0,0');
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());

        // (0,0) start, (1,0) the room east of it, (2,0) further east, (1,1) south of (1,0) without a door
        $this->seedTiles(['0,0' => 'fourSideRoom', '1,0' => 'fourSideRoom', '2,0' => 'fourSideRoom', '1,1' => 'fourSideRoom']);
        $this->setProperty('transitions', ['1,0' => ['0,0', '2,0'], '0,0' => ['1,0'], '2,0' => ['1,0']]);
    }

    #[Test]
    public function valdukLeavesTheLootAndKeepsMoving(): void
    {
        $this->hero = HeroType::BARBARIAN;
        $this->position = FieldPlace::fromString('1,0');
        $this->wonFightAt('1,0', autoCollected: false);

        [, $messages] = $this->createTester()->handle(
            $this->field->continueAfterVictory(...),
            new ContinueAfterVictory($this->gameId, $this->playerId, $this->turnId),
        );

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::TIRELESS, $action->action);
        self::assertSame('1,0', $action->additionalData['position'] ?? null);
        self::assertSame(['name' => 'giant_rat', 'type' => 'dagger'], $action->additionalData['leftItem'] ?? null);
        self::assertSame('tireless', $action->additionalData['heroAbility'] ?? null);
        self::assertArrayHasKey('1,0', $this->field->getItems(), 'The loot stays on the tile');
    }

    #[Test]
    public function valdukMayLeaveAGuardedChestAndWalkOn(): void
    {
        $this->hero = HeroType::BARBARIAN;
        $this->position = FieldPlace::fromString('1,0');
        $this->setProperty('items', ['1,0' => Item::createFallen()]);

        $tester = $this->createTester();
        $tester->handle($this->field->onBattleCompleted(...), $this->wonAgainstTheFallen());

        self::assertArrayHasKey('1,0', $this->field->getItems(), 'Valduk\'s chest is not collected for him');
        self::assertFalse($this->field->getLastBattleInfo()['reward']['autoCollected'] ?? true);

        [, $messages] = $tester->handle($this->field->continueAfterVictory(...), new ContinueAfterVictory($this->gameId, $this->playerId, $this->turnId));
        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertSame(TurnAction::TIRELESS, $action?->action);
    }

    #[Test]
    public function anotherHerosGuardedChestIsCollectedAtOnce(): void
    {
        $this->hero = HeroType::WARRIOR;
        $this->position = FieldPlace::fromString('1,0');
        $this->setProperty('items', ['1,0' => Item::createFallen()]);

        $this->createTester()->handle($this->field->onBattleCompleted(...), $this->wonAgainstTheFallen());

        self::assertArrayNotHasKey('1,0', $this->field->getItems());
        self::assertTrue($this->field->getLastBattleInfo()['reward']['autoCollected'] ?? false);
    }

    #[Test]
    public function tirelessIsValdukOnly(): void
    {
        $this->hero = HeroType::WARRIOR;
        $this->position = FieldPlace::fromString('1,0');
        $this->wonFightAt('1,0', autoCollected: false);

        $this->expectException(\DomainException::class);
        $this->createTester()->handle($this->field->continueAfterVictory(...), new ContinueAfterVictory($this->gameId, $this->playerId, $this->turnId));
    }

    #[Test]
    public function tirelessNeedsLootLeftOnTheTile(): void
    {
        $this->hero = HeroType::BARBARIAN;
        $this->position = FieldPlace::fromString('1,0');
        $this->wonFightAt('1,0', autoCollected: true);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No won fight with loot left on this tile');
        $this->createTester()->handle($this->field->continueAfterVictory(...), new ContinueAfterVictory($this->gameId, $this->playerId, $this->turnId));
    }

    #[Test]
    public function tirelessNeedsAWonFight(): void
    {
        $this->hero = HeroType::BARBARIAN;
        $this->position = FieldPlace::fromString('1,0');
        $this->wonFightAt('1,0', autoCollected: false);
        $this->setProperty('lastBattleInfo', ['result' => 'lose'] + $this->field->getLastBattleInfo());

        $this->expectException(\DomainException::class);
        $this->createTester()->handle($this->field->continueAfterVictory(...), new ContinueAfterVictory($this->gameId, $this->playerId, $this->turnId));
    }

    #[Test]
    public function markulMayRetreatOntoDoorNeighboursWithoutMonsters(): void
    {
        $this->setProperty('items', ['2,0' => Item::createGiantRat()]);

        $this->createTester()->handle($this->field->onRetreatRequired(...), $this->retreatRequired('1,0', from: '3,3'));

        self::assertSame(
            ['playerId' => $this->playerId->toString(), 'turnId' => $this->turnId->toString(), 'position' => '1,0', 'options' => ['0,0']],
            $this->field->getPendingRetreat(),
            '(2,0) holds a monster, (1,1) has no door',
        );
        self::assertSame(['0,0'], $this->field->getLastBattleInfo()['retreatOptions'] ?? null);
    }

    #[Test]
    public function withoutAnOpenNeighbourMarkulGoesBackWhereHeCameFrom(): void
    {
        $this->createTester()->handle($this->field->onRetreatRequired(...), $this->retreatRequired('1,1', from: '2,0'));

        self::assertSame(['2,0'], $this->field->getPendingRetreat()['options'] ?? null);
    }

    #[Test]
    public function theChosenRetreatMovesHimAndEndsTheTurn(): void
    {
        $tester = $this->createTester();
        $tester->handle($this->field->onRetreatRequired(...), $this->retreatRequired('1,0', from: '3,3'));

        try {
            $tester->handle($this->field->chooseRetreat(...), new ChooseRetreat($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString('1,1')));
            self::fail('(1,1) is not an option');
        } catch (\DomainException) {
        }

        [, $messages] = $tester->handle(
            $this->field->chooseRetreat(...),
            new ChooseRetreat($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString('2,0')),
        );

        $reset = $this->findMessage($messages, ResetPlayerPosition::class);
        self::assertInstanceOf(ResetPlayerPosition::class, $reset);
        self::assertSame('2,0', $reset->position->toString());
        $moved = $this->findMessage($messages, PlayerMoved::class);
        self::assertInstanceOf(PlayerMoved::class, $moved);
        self::assertTrue($moved->isBattleReturn, 'A fountain heals and a Cursed Room rolls like after any lost fight');
        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::RETREAT, $action->action);
        self::assertInstanceOf(EndTurn::class, $this->findMessage($messages, EndTurn::class));
        self::assertNull($this->field->getPendingRetreat());
    }

    #[Test]
    public function aRetreatNeverChosenTakesTheFirstOptionAtTheTurnEnd(): void
    {
        $tester = $this->createTester();
        $tester->handle($this->field->onRetreatRequired(...), $this->retreatRequired('1,0', from: '3,3'));

        [, $messages] = $tester->handle(
            $this->field->onTurnEnded(...),
            new TurnEnded($this->turnId, $this->gameId, $this->playerId, new \DateTimeImmutable()),
        );

        $reset = $this->findMessage($messages, ResetPlayerPosition::class);
        self::assertInstanceOf(ResetPlayerPosition::class, $reset);
        self::assertSame('2,0', $reset->position->toString(), 'Options follow the sides: top, right, bottom, left');
        self::assertNull($this->field->getPendingRetreat());
    }

    #[Test]
    public function lorraineListensAtTheTileSheJustPlaced(): void
    {
        $this->hero = HeroType::RANGER;
        $this->seedTiles(['0,0' => 'fourSideRoom', '1,0' => 'battleArena']);
        $this->setProperty('items', ['1,0' => Item::createGiantRat()]);

        [, $messages] = $this->createTester()->handle(
            $this->field->listen(...),
            new Listen($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString('1,0')),
        );

        $action = $this->findMessage($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::LISTEN, $action->action);
        self::assertSame('giant_rat', $action->additionalData['item']['name'] ?? null);
        self::assertSame(['battle_arena'], $action->additionalData['features'] ?? null);
        self::assertNull($this->findMessage($messages, PlayerMoved::class), 'She does not enter the tile');
        self::assertSame([], $this->field->getActivatedArenas(), 'A listened arena is not activated');
    }

    #[Test]
    public function listeningNeedsLorraineAndAnAdjacentPlacedTile(): void
    {
        $this->hero = HeroType::THIEF;

        try {
            $this->createTester()->handle($this->field->listen(...), new Listen($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString('1,0')));
            self::fail('Only Lorraine listens');
        } catch (\DomainException) {
        }

        $this->hero = HeroType::RANGER;
        foreach (['2,0' => 'not adjacent', '0,1' => 'no tile'] as $place => $reason) {
            try {
                $this->createTester()->handle($this->field->listen(...), new Listen($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString($place)));
                self::fail($reason);
            } catch (\DomainException) {
            }
        }

        $this->setProperty('pendingFateweaver', ['fieldPlace' => '1,0', 'items' => []]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Choose the Fateweaver token first');
        $this->createTester()->handle($this->field->listen(...), new Listen($this->gameId, $this->playerId, $this->turnId, FieldPlace::fromString('1,0')));
    }

    private function wonFightAt(string $place, bool $autoCollected): void
    {
        $loot = Item::createGiantRat()->defeatMonster();
        $this->setProperty('items', [$place => $loot]);
        $this->setProperty('lastBattleInfo', [
            'player' => $this->playerId->toString(),
            'position' => $place,
            'result' => 'win',
            'reward' => ['type' => $loot->type->value, 'itemId' => $loot->itemId->toString(), 'autoCollected' => $autoCollected],
        ]);
    }

    private function wonAgainstTheFallen(): \App\Game\Battle\BattleCompleted
    {
        return new \App\Game\Battle\BattleCompleted(
            battleId: Uuid::v7(),
            gameId: $this->gameId,
            playerId: $this->playerId,
            result: \App\Game\Battle\BattleResult::WIN,
            diceResults: [6, 6],
            diceRollDamage: 12,
            itemDamage: 1,
            totalDamage: 13,
            monsterHP: 12,
        );
    }

    private function retreatRequired(string $position, string $from): RetreatRequired
    {
        return new RetreatRequired(
            gameId: $this->gameId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            position: FieldPlace::fromString($position),
            teleportedFrom: FieldPlace::fromString($from),
        );
    }

    /**
     * @param array<string, string> $tilesByPlace
     */
    private function seedTiles(array $tilesByPlace): void
    {
        $tiles = [];
        $cache = [];
        foreach ($tilesByPlace as $place => $name) {
            $tileId = Uuid::v7();
            $tiles[$place] = $tileId;
            $cache[$tileId->toString()] = Tile::fromDeckTile($tileId, DeckTile::createFromName($name));
        }
        $this->setProperty('tiles', $tiles);
        $this->setProperty('tilesCache', $cache);
    }

    private function setProperty(string $name, mixed $value): void
    {
        (new \ReflectionProperty(Field::class, $name))->setValue($this->field, $value);
    }

    private function createTester(): MessageBusTester
    {
        $hero = $this->hero;
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
            static fn(GetGame $_query): object => $game,
            static fn(GetPlayer $_query): object => new class ($hero) {
                public function __construct(private readonly ?HeroType $hero) {}

                public function getHero(): ?HeroType
                {
                    return $this->hero;
                }

                public function getSkills(): ?\App\Game\Player\HeroSkills
                {
                    $hero = $this->getHero();

                    return $hero instanceof \App\Game\Player\HeroType ? \App\Game\Player\HeroSkills::of($hero) : null;
                }
            },
            fn(GetCurrentPlayer $_query): Uuid => $this->playerId,
            fn(GetCurrentTurn $_query): Uuid => $this->turnId,
            fn(GetPlayerPosition $_query): FieldPlace => $this->position,
            static fn(\App\Game\Player\AddItemToInventory $_command) => null,
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
