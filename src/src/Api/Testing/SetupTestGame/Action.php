<?php

declare(strict_types=1);

namespace App\Api\Testing\SetupTestGame;

use App\Api\Error;
use App\Game\Testing\PlayerTestConfig;
use App\Game\Testing\SetupTestGame;
use App\Game\Testing\TestGameConfiguration;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/test/setup-game', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, MessageBus $messageBus): Response|Error
    {
        // Only allow in test environment or when explicitly enabled
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        if ($appEnv !== 'test' && $appEnv !== 'dev') {
            return new Error(Uuid::v7(), 'Test setup only available in test/dev environment', 403);
        }

        try {
            $playerConfigs = [];
            foreach ($request->playerConfigs as $playerId => $config) {
                $playerConfigs[$playerId] = new PlayerTestConfig(
                    maxHp: $config['maxHp'] ?? null,
                    maxActions: $config['maxActions'] ?? null,
                );
            }

            $configuration = new TestGameConfiguration(
                diceRolls: $request->diceRolls,
                tileSequence: $request->tileSequence,
                itemSequence: $request->itemSequence,
                playerConfigs: $playerConfigs,
            );

            $messageBus->dispatch(new SetupTestGame(
                gameId: Uuid::fromString($request->gameId),
                configuration: $configuration,
            ));
        } catch (\Throwable $e) {
            return new Error(Uuid::fromString($request->gameId), 'Failed to setup test game: ' . $e->getMessage());
        }

        return new Response(Uuid::fromString($request->gameId));
    }
}
