<?php

declare(strict_types=1);

namespace App\Api\Game\GetPlayerStatus;

use App\Api\Error;
use App\Game\Player\GetPlayerStatus;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/player/status', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload]
        Request $request,
        MessageBus $messageBus,
    ): Response|Error {
        try {
            $playerStatus = $messageBus->dispatch(new GetPlayerStatus(
                playerId: $request->playerId,
            ));

            return new Response(
                playerId: $request->playerId,
                hp: $playerStatus['hp'],
                isStunned: $playerStatus['isStunned'],
                inventory: $playerStatus['inventory'],
            );
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Could not get player status: ' . $e->getMessage());
        }
    }
}
