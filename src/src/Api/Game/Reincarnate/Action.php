<?php

declare(strict_types=1);

namespace App\Api\Game\Reincarnate;

use App\Api\Error;
use App\Game\Field\Reincarnate;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Warrior "Reincarnation": choose the healing fountain to teleport to after losing the last HP.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/reincarnate', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new Reincarnate(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                targetPosition: $request->targetPosition,
            ));

            return new Response($request->gameId, $request->playerId, $request->targetPosition->toString());
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to reincarnate: ' . $e->getMessage());
        }
    }
}
