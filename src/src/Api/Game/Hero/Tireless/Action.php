<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\Tireless;

use App\Api\Error;
use App\Game\Field\ContinueAfterVictory;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Valduk "Tireless": leave the loot of the won fight on its tile and keep moving.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/tireless', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new ContinueAfterVictory(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
            ));

            return new Response($request->gameId, $request->playerId);
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to keep moving: ' . $e->getMessage());
        }
    }
}
