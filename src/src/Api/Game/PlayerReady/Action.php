<?php

declare(strict_types=1);

namespace App\Api\Game\PlayerReady;

use App\Api\Error;
use App\Game\Player\GetReady;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/player/ready', name: 'api_player_ready', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload]
        Request $request,
        MessageBus $messageBus,
    ): Response|Error {
        try {
            $messageBus->dispatch(
                new GetReady(
                    playerId: $request->playerId,
                    gameId: $request->gameId,
                ),
            );
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Could not get player ready: ' . $e->getMessage());
        }

        return new Response(
            playerId: $request->playerId,
            gameId: $request->gameId,
        );
    }
}
