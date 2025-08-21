<?php

declare(strict_types=1);

namespace App\Game\Testing;

use App\Game\Player\Player;
use App\Game\Turn\GameTurn;
use Doctrine\ORM\EntityManagerInterface;
use Telephantast\MessageBus\Handler\Mapping\Handler;

final readonly class SetPlayerTestStateHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    #[Handler]
    public function __invoke(SetPlayerTestState $command): void
    {
        // Only allow in test/dev environment
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        if ($appEnv !== 'test' && $appEnv !== 'dev') {
            return;
        }

        $config = $command->config;

        // Update player state
        $player = $this->entityManager->getRepository(Player::class)->find($command->playerId);
        if ($player === null) {
            return;
        }

        // Only set maxHp as per simplified PlayerTestConfig
        if ($config->maxHp !== null) {
            $player->setTestMaxHp($config->maxHp);
        }

        // Update turn max actions if specified
        if ($config->maxActions !== null) {
            $currentTurn = $this->entityManager->getRepository(GameTurn::class)
                ->findOneBy([
                    'gameId' => $command->gameId,
                    'playerId' => $command->playerId,
                    'endTime' => null,  // Find the active turn (not ended)
                ]);

            if ($currentTurn !== null) {
                $currentTurn->setTestMaxActions($config->maxActions);
            }
        }

        $this->entityManager->flush();
    }
}
