<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\HealLimit;

use App\Api\Error;
use App\Game\Player\SetHealLimit;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Valduk "Berserk": choose how many HP a heal restores at most.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/hero/heal-limit', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new SetHealLimit(
                gameId: $request->gameId,
                playerId: $request->playerId,
                hp: $request->hp,
            ));

            return new Response($request->gameId, $request->playerId, $request->hp);
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to set the heal limit: ' . $e->getMessage());
        }
    }
}
