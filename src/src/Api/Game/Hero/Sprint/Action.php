<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\Sprint;

use App\Api\Error;
use App\Game\Movement\Commands\DeclareSprint;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Hanna "Sprint": up to 8 moves this turn, discovered tiles only.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/sprint', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new DeclareSprint(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
            ));

            return new Response($request->gameId, $request->playerId, 8);
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to sprint: ' . $e->getMessage());
        }
    }
}
