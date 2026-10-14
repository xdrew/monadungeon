<?php

declare(strict_types=1);

namespace App\Api\Game\MagicSwap;

use App\Api\Error;
use App\Game\Movement\Commands\MagicSwap;
use App\Game\Movement\GetPlayerPosition;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Warlock "Magic Swap": trade places with another hero at the beginning of the turn.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/magic-swap', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new MagicSwap(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                targetPlayerId: $request->targetPlayerId,
            ));

            $position = $this->messageBus->dispatch(new GetPlayerPosition($request->gameId, $request->playerId));

            return new Response($request->gameId, $request->playerId, $request->targetPlayerId, $position->toString());
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to swap places: ' . $e->getMessage());
        }
    }
}
