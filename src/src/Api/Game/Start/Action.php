<?php

declare(strict_types=1);

namespace App\Api\Game\Start;

use App\Api\Error;
use App\Game\GameLifecycle\StartGame;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/start', name: 'api_game_start', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload]
        Request $request,
        MessageBus $messageBus,
    ): Response|Error {
        try {
            $messageBus->dispatch(new StartGame(gameId: $request->gameId));
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Could not start the game: ' . $e->getMessage());
        }

        return new Response(
            gameId: $request->gameId,
        );
    }
}
