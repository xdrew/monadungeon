<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\GameLifecycle\GameCreated;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

/**
 * Wizard "Astral Walking": the available moves include adjacent placed tiles behind walls; tile placement is unchanged.
 *
 * Dungeon: 0,0 and 1,0 are connected by a door; 0,1 is placed next to 0,0 but behind a wall; -1,0 is an empty place with a door.
 */
#[CoversClass(Field::class)]
final class AstralWalkingPlacesTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Field $field;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->field = Field::create(new GameCreated($this->gameId, new \DateTimeImmutable(), 10), startMessageContext());

        $tiles = new \ReflectionProperty(Field::class, 'tiles');
        $tiles->setValue($this->field, ['0,0' => Uuid::v7(), '1,0' => Uuid::v7(), '0,1' => Uuid::v7()]);
        $transitions = new \ReflectionProperty(Field::class, 'transitions');
        $transitions->setValue($this->field, ['0,0' => ['1,0', '-1,0'], '1,0' => ['0,0'], '0,1' => []]);
    }

    #[Test]
    public function wizardMayMoveOntoTheTileBehindTheWall(): void
    {
        $places = $this->availablePlaces(HeroType::WIZARD);

        self::assertSame(['1,0', '-1,0', '0,1'], $places['moveTo']);
        self::assertSame(['-1,0'], $places['placeTile'], 'New tiles are still placed only through a door');
    }

    #[Test]
    public function otherHeroesOnlySeeTheDoors(): void
    {
        $places = $this->availablePlaces(HeroType::THIEF);

        self::assertSame(['1,0', '-1,0'], $places['moveTo']);
        self::assertSame(['-1,0'], $places['placeTile']);
    }

    /**
     * @return array{moveTo: list<string>, placeTile: list<string>}
     */
    private function availablePlaces(HeroType $hero): array
    {
        $player = new class ($hero) {
            public function __construct(private readonly HeroType $hero) {}

            public function getHP(): int
            {
                return 5;
            }

            public function isDefeated(): bool
            {
                return false;
            }

            public function getHero(): HeroType
            {
                return $this->hero;
            }
        };

        $tester = MessageBusTester::create(
            static fn(GetPlayerPosition $_query): FieldPlace => FieldPlace::fromString('0,0'),
            static fn(GetPlayer $_query): object => $player,
        );

        /** @var array{moveTo: list<string>, placeTile: list<string>} */
        return $this->field->getAvailablePlacesForPlayer(new GetAvailablePlacesForPlayer(
            gameId: $this->gameId,
            playerId: $this->playerId,
            messageBus: $tester->messageBus(),
        ));
    }
}
