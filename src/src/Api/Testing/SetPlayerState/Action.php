<?php

declare(strict_types=1);

namespace App\Api\Testing\SetPlayerState;

use App\Api\Error;
use App\Game\Testing\PlayerTestConfig;
use App\Game\Testing\SetPlayerTestState;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/test/player-state', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, MessageBus $messageBus): Response|Error
    {
        // Only allow in test/dev environment
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        if ($appEnv !== 'test' && $appEnv !== 'dev') {
            return new Error(Uuid::v7(), 'Player state modification only available in test/dev environment');
        }

        try {
            $config = new PlayerTestConfig(
                maxHp: $request->maxHp,
                maxActions: $request->maxActions,
            );

            $messageBus->dispatch(new SetPlayerTestState(
                gameId: $request->gameId,
                playerId: $request->playerId,
                config: $config,
            ));
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Failed to set player state: ' . $e->getMessage());
        }

        return new Response($request->playerId);
    }
}
