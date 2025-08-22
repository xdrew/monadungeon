<?php

declare(strict_types=1);

namespace App\Game\Leaderboard;

use App\Game\GameLifecycle\GameEnded;
use App\Game\Player\Player;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Telephantast\MessageBus\Handler\Mapping\Handler;

final class UpdateLeaderboardOnGameEnd
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
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
            $username = $player->getUsername();
            $walletAddress = $player->getWalletAddress();
            $externalId = $player->getExternalId();
            
            // Skip players without username (username is required for identification)
            if (empty($username)) {
                $this->logger->debug('Skipping player without username for leaderboard', [
                    'playerId' => $player->getPlayerId()->toString(),
                    'externalId' => $externalId,
                ]);
                continue;
            }

            $playerId = $player->getPlayerId();
            $isWinner = $event->winnerId && $event->winnerId->equals($playerId);

            // Start a transaction for atomic update
            $this->entityManager->beginTransaction();
            
            try {
                $leaderboardEntry = null;
                
                // Try to find existing entry by wallet address if available
                if (!empty($walletAddress)) {
                    $leaderboardEntry = $leaderboardRepository->findOneBy(['walletAddress' => $walletAddress]);
                }
                
                // If not found by wallet, try by username and externalId combination
                if (!$leaderboardEntry && !empty($externalId)) {
                    $leaderboardEntry = $leaderboardRepository->findOneBy([
                        'username' => $username,
                        'externalId' => $externalId,
                    ]);
                }
                
                // For AI players without wallet/externalId, use username only
                if (!$leaderboardEntry && empty($walletAddress) && empty($externalId)) {
                    $leaderboardEntry = $leaderboardRepository->findOneBy([
                        'username' => $username,
                        'walletAddress' => null,
                        'externalId' => null,
                    ]);
                }

                if ($leaderboardEntry) {
                    // Lock the row for update
                    $this->entityManager->lock($leaderboardEntry, LockMode::PESSIMISTIC_WRITE);
                    
                    // Update existing entry
                    $leaderboardEntry->updateStats($isWinner);
                    
                    // Update username if it changed
                    if ($leaderboardEntry->getUsername() !== $username) {
                        $leaderboardEntry->updateUsername($username);
                    }
                    
                    // Update external ID if provided and different
                    if ($externalId && $leaderboardEntry->getExternalId() !== $externalId) {
                        $leaderboardEntry->updateExternalId($externalId);
                    }
                } else {
                    // Create new leaderboard entry
                    $leaderboardEntry = new Leaderboard(
                        username: $username,
                        walletAddress: $walletAddress,
                        externalId: $externalId,
                    );
                    $leaderboardEntry->updateStats($isWinner);
                    $this->entityManager->persist($leaderboardEntry);
                }

                $this->entityManager->flush();
                $this->entityManager->commit();

                $this->logger->info('Successfully updated leaderboard entry', [
                    'gameId' => $event->gameId->toString(),
                    'playerId' => $playerId->toString(),
                    'username' => $username,
                    'walletAddress' => $walletAddress,
                    'externalId' => $externalId,
                    'isWinner' => $isWinner,
                    'victories' => $leaderboardEntry->getVictories(),
                    'totalGames' => $leaderboardEntry->getTotalGames(),
                    'isAIPlayer' => empty($walletAddress) && empty($externalId),
                ]);
            } catch (\Throwable $e) {
                $this->entityManager->rollback();
                
                $this->logger->error('Failed to update leaderboard entry', [
                    'gameId' => $event->gameId->toString(),
                    'playerId' => $playerId->toString(),
                    'username' => $username,
                    'walletAddress' => $walletAddress,
                    'externalId' => $externalId,
                    'isWinner' => $isWinner,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }
}