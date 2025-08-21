<?php

declare(strict_types=1);

namespace App\Api\Game\GetCurrentTurn;

use App\Api\Error;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/{gameId}/current-turn', methods: ['GET'])]
    public function __invoke(string $gameId, MessageBus $messageBus): Response|Error
    {
        $gameUuid = Uuid::fromString($gameId);

        try {
            $currentTurnUuid = $messageBus->dispatch(new GetCurrentTurn($gameUuid));

            if ($currentTurnUuid === null) {
                return new Error(Uuid::v7(), 'No current turn found for this game');
            }
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Cannot get current turn: ' . $e->getMessage());
        }

        return new Response($currentTurnUuid);
    }
}
