<?php

declare(strict_types=1);

namespace App\Game\Leaderboard;

use App\Game\GameLifecycle\GameEnded;
use App\Game\Player\Player;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Telephantast\MessageBus\Handler\Mapping\Handler;

final readonly class UpdateLeaderboardOnGameEnd
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {}

    #[Handler]
    public function __invoke(GameEnded $event): void
    {
        $this->logger->info('Updating leaderboard for game end', [
            'gameId' => $event->gameId->toString(),
            'winnerId' => $event->winnerId?->toString(),
        ]);

        // Get all players from the game
        $playerRepository = $this->entityManager->getRepository(Player::class);
        $players = $playerRepository->findBy(['gameId' => $event->gameId]);

        $leaderboardRepository = $this->entityManager->getRepository(Leaderboard::class);

        foreach ($players as $player) {
            $externalId = $player->getExternalId();
            // The board is by players, so by accounts: the AI and seats without an account (old games,
            // test-mode setups) are not on it
            if ($player->isAi() || empty($externalId)) {
                $this->logger->debug('Not on the leaderboard: AI or no account', [
                    'playerId' => $player->getPlayerId()->toString(),
                    'isAi' => $player->isAi(),
                ]);

                continue;
            }

            $playerId = $player->getPlayerId();
            // equal top scores share the victory: each of them counts a win
            $winners = $event->winnerIds !== [] ? $event->winnerIds : array_filter([$event->winnerId]);
            $isWinner = array_filter($winners, static fn(Uuid $id): bool => $id->equals($playerId)) !== [];
            $username = $player->getUsername() ?: 'Adventurer';

            $leaderboardEntry = $leaderboardRepository->findOneBy(['externalId' => $externalId]);
            if ($leaderboardEntry) {
                $leaderboardEntry->updateStats($isWinner);
                if ($leaderboardEntry->getUsername() !== $username) {
                    $leaderboardEntry->updateUsername($username);
                }
            } else {
                $leaderboardEntry = new Leaderboard(username: $username, externalId: $externalId);
                $leaderboardEntry->updateStats($isWinner);
                $this->entityManager->persist($leaderboardEntry);
            }

            $this->logger->info('Successfully updated leaderboard entry', [
                'gameId' => $event->gameId->toString(),
                'playerId' => $playerId->toString(),
                'externalId' => $externalId,
                'isWinner' => $isWinner,
                'victories' => $leaderboardEntry->getVictories(),
                'totalGames' => $leaderboardEntry->getTotalGames(),
            ]);
        }
    }
}
