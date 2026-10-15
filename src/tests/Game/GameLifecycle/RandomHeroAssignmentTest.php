<?php

declare(strict_types=1);

namespace App\Tests\Game\GameLifecycle;

use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Testing\TestMode;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Heroes are dealt at random: AI players always, everybody in a random-heroes game; never a hero someone holds,
 * the Karak: Regent heroes only with the expansion.
 */
#[CoversClass(Game::class)]
final class RandomHeroAssignmentTest extends TestCase
{
    /**
     * @var array<string, HeroType|null> playerId => hero, as PlayerAdded reported it
     */
    private array $heroes = [];

    protected function tearDown(): void
    {
        TestMode::getInstance()->disable();
        TestMode::getInstance()->reset();
    }

    #[Test]
    public function anAiPlayerIsDealtABaseHeroInABaseGame(): void
    {
        $game = $this->createGame();

        $hero = $this->addPlayer($game, isAi: true);

        self::assertNotNull($hero);
        self::assertFalse($hero->isRegent());
    }

    #[Test]
    public function anAiPlayerMayBeDealtARegentHeroInARegentGame(): void
    {
        $dealt = [];
        for ($i = 0; $i < 60; ++$i) {
            $this->heroes = [];
            $game = $this->createGame(regent: true);
            $dealt[] = $this->addPlayer($game, isAi: true);
        }

        self::assertNotContains(null, $dealt);
        self::assertNotEmpty(array_filter($dealt, static fn(?HeroType $hero): bool => $hero?->isRegent() === true));
    }

    #[Test]
    public function aHumanWithoutAHeroGetsNoneWhenHeroesAreChosen(): void
    {
        $game = $this->createGame();

        self::assertNull($this->addPlayer($game));
    }

    #[Test]
    public function aHumanIsDealtAHeroInARandomHeroesGame(): void
    {
        $game = $this->createGame(randomHeroes: true);

        self::assertNotNull($this->addPlayer($game));
    }

    #[Test]
    public function theAiNeverGetsTheHeroTheHumanPicked(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->heroes = [];
            $game = $this->createGame();
            $this->addPlayer($game, hero: HeroType::WARRIOR);

            self::assertNotSame(HeroType::WARRIOR, $this->addPlayer($game, isAi: true));
        }
    }

    #[Test]
    public function aSuppliedHeroIsKeptInARandomHeroesGame(): void
    {
        $game = $this->createGame(randomHeroes: true);

        self::assertSame(HeroType::ORACLE, $this->addPlayer($game, hero: HeroType::ORACLE));
    }

    #[Test]
    public function aSuppliedRegentHeroIsStillRefusedInABaseGame(): void
    {
        $game = $this->createGame(randomHeroes: true);

        $this->expectException(\DomainException::class);
        $this->addPlayer($game, hero: HeroType::RANGER);
    }

    #[Test]
    public function fivePlayersOfABaseGameGetFiveDifferentHeroes(): void
    {
        $game = $this->createGame(randomHeroes: true);

        for ($i = 0; $i < 5; ++$i) {
            $this->addPlayer($game, isAi: $i > 0);
        }

        $dealt = array_map(static fn(?HeroType $hero): string => $hero?->value ?? 'none', array_values($this->heroes));
        self::assertCount(5, array_unique($dealt));
        self::assertNotContains('none', $dealt);
    }

    #[Test]
    public function testModeDealsTheFirstFreeHero(): void
    {
        TestMode::getInstance()->enable();
        $game = $this->createGame(randomHeroes: true);

        self::assertSame(HeroType::WARRIOR, $this->addPlayer($game));
        self::assertSame(HeroType::THIEF, $this->addPlayer($game, isAi: true));
    }

    #[Test]
    public function theFlagIsReportedOnCreation(): void
    {
        $gameId = Uuid::v7();
        [$game, $messages] = handle(Game::create(...), new CreateGame(gameId: $gameId, randomHeroes: true));

        self::assertTrue($game->isRandomHeroes());
        self::assertInstanceOf(GameCreated::class, $messages[0]);
        self::assertTrue($messages[0]->randomHeroes);
    }

    private function createGame(bool $regent = false, bool $randomHeroes = false): Game
    {
        [$game] = handle(Game::create(...), new CreateGame(gameId: Uuid::v7(), regent: $regent, randomHeroes: $randomHeroes));

        return $game;
    }

    /**
     * Adds a player and returns the hero PlayerAdded reported (dealt or supplied).
     */
    private function addPlayer(Game $game, bool $isAi = false, ?HeroType $hero = null): ?HeroType
    {
        $playerId = Uuid::v7();
        $heroes = &$this->heroes;
        $tester = MessageBusTester::create(static function (GetPlayer $query) use (&$heroes): object {
            $hero = $heroes[$query->playerId->toString()] ?? null;

            return new class ($hero) {
                public function __construct(private readonly ?HeroType $hero) {}

                public function getChosenHero(): ?HeroType
                {
                    return $this->hero;
                }
            };
        });

        [, $messages] = $tester->handle($game->addPlayer(...), new AddPlayer(
            gameId: $game->getGameId(),
            playerId: $playerId,
            isAi: $isAi,
            hero: $hero,
        ));

        $added = array_values(array_filter($messages, static fn(object $message): bool => $message instanceof PlayerAdded))[0] ?? null;
        self::assertInstanceOf(PlayerAdded::class, $added);
        $this->heroes[$playerId->toString()] = $added->hero;

        return $added->hero;
    }
}
