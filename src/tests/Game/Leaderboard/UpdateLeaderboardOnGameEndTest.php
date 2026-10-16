<?php

declare(strict_types=1);

namespace App\Tests\Game\Leaderboard;

use App\Game\GameLifecycle\GameEnded;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Leaderboard\Leaderboard;
use App\Game\Leaderboard\UpdateLeaderboardOnGameEnd;
use App\Game\Player\Player;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use function App\Tests\Infrastructure\MessageBus\handle;

#[CoversClass(UpdateLeaderboardOnGameEnd::class)]
final class UpdateLeaderboardOnGameEndTest extends TestCase
{
    private Uuid $gameId;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
    }

    #[Test]
    public function playersWithAnAccountAreCountedTheAiAndSeatsWithoutOneAreNot(): void
    {
        $ada = $this->player('acc-ada', 'Ada');
        $ai = $this->player(null, 'AI Player', true);
        $oldSeat = $this->player(null, 'Player 2');
        $persisted = [];

        ($this->updater([$ada, $ai, $oldSeat], [], $persisted))(new GameEnded($this->gameId, $ada->getPlayerId(), []));

        self::assertCount(1, $persisted);
        self::assertSame('acc-ada', $persisted[0]->getExternalId());
        self::assertSame('Ada', $persisted[0]->getUsername());
        self::assertSame(1, $persisted[0]->getVictories());
        self::assertSame(1, $persisted[0]->getTotalGames());
    }

    #[Test]
    public function aKnownAccountKeepsItsRowAndTakesTheNewName(): void
    {
        $row = new Leaderboard(username: 'Ada', externalId: 'acc-ada');
        $row->updateStats(true);
        $renamed = $this->player('acc-ada', 'Ada the Bold');
        $rival = $this->player('acc-bob', 'Bob');
        $persisted = [];

        ($this->updater([$renamed, $rival], ['acc-ada' => $row], $persisted))(new GameEnded($this->gameId, $rival->getPlayerId(), []));

        self::assertSame('Ada the Bold', $row->getUsername());
        self::assertSame(1, $row->getVictories());
        self::assertSame(2, $row->getTotalGames());
        self::assertCount(1, $persisted);
        self::assertSame('Bob', $persisted[0]->getUsername());
    }

    private function player(?string $account, ?string $name, bool $isAi = false): Player
    {
        [$player] = handle(Player::onPlayerAddedToGame(...), new PlayerAdded(
            gameId: $this->gameId,
            playerId: Uuid::v7(),
            externalId: $account,
            username: $name,
            isAi: $isAi,
        ));
        self::assertInstanceOf(Player::class, $player);

        return $player;
    }

    /**
     * @param list<Player> $players
     * @param array<string, Leaderboard> $existing by external id
     * @param list<Leaderboard> $persisted
     */
    private function updater(array $players, array $existing, array &$persisted): UpdateLeaderboardOnGameEnd
    {
        $playerRepository = $this->createMock(EntityRepository::class);
        $playerRepository->method('findBy')->willReturn($players);
        $boardRepository = $this->createMock(EntityRepository::class);
        $boardRepository->method('findOneBy')->willReturnCallback(
            static fn(array $criteria): ?Leaderboard => $existing[$criteria['externalId'] ?? ''] ?? null,
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn(string $class) => $class === Player::class ? $playerRepository : $boardRepository,
        );
        $entityManager->method('persist')->willReturnCallback(static function (object $entry) use (&$persisted): void {
            $persisted[] = $entry;
        });

        return new UpdateLeaderboardOnGameEnd($entityManager, new NullLogger());
    }
}
