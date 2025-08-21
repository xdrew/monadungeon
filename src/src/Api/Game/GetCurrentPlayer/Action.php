<?php

declare(strict_types=1);

namespace App\Api\Game\GetCurrentPlayer;

use App\Api\Error;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/{gameId}/current-player', methods: ['GET'])]
    public function __invoke(string $gameId, MessageBus $messageBus): Response|Error
    {
        $gameUuid = Uuid::fromString($gameId);

        try {
            $currentPlayerUuid = $messageBus->dispatch(new GetCurrentPlayer($gameUuid));

            if ($currentPlayerUuid === null) {
                return new Error(Uuid::v7(), 'No current player found for this game');
            }
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Cannot get current player: ' . $e->getMessage());
        }

        return new Response($currentPlayerUuid);
    }
}
