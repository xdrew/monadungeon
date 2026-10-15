<?php

declare(strict_types=1);

namespace App\Tests\Game\GameLifecycle;

use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Player\HeroType;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Karak: Regent heroes join only a game with the expansion on.
 */
#[CoversClass(Game::class)]
final class RegentHeroGateTest extends TestCase
{
    #[Test]
    public function aRegentHeroCannotJoinABaseGame(): void
    {
        $gameId = Uuid::v7();
        [$game] = handle(Game::create(...), new CreateGame(gameId: $gameId));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Lady Lorraine, the Ranger needs the Karak: Regent expansion');
        handle($game->addPlayer(...), new AddPlayer(gameId: $gameId, playerId: Uuid::v7(), hero: HeroType::RANGER));
    }

    #[Test]
    public function regentAndBaseHeroesJoinARegentGame(): void
    {
        $gameId = Uuid::v7();
        [$game] = handle(Game::create(...), new CreateGame(gameId: $gameId, regent: true));

        foreach ([HeroType::BARBARIAN, HeroType::WARRIOR] as $hero) {
            [, $messages] = handle($game->addPlayer(...), new AddPlayer(gameId: $gameId, playerId: Uuid::v7(), hero: $hero));
            $added = $messages[0] ?? null;
            self::assertInstanceOf(PlayerAdded::class, $added);
            self::assertSame($hero, $added->hero);
        }
    }

    #[Test]
    public function baseHeroesJoinABaseGame(): void
    {
        $gameId = Uuid::v7();
        [$game] = handle(Game::create(...), new CreateGame(gameId: $gameId));

        [, $messages] = handle($game->addPlayer(...), new AddPlayer(gameId: $gameId, playerId: Uuid::v7(), hero: HeroType::ORACLE));

        self::assertInstanceOf(PlayerAdded::class, $messages[0] ?? null);
    }
}
