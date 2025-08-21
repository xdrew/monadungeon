<?php

declare(strict_types=1);

namespace App\Api\Game\UseSpell;

use App\Api\Error;
use App\Game\Player\UseSpell;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/use-spell', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new UseSpell(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                spellId: $request->spellId,
                targetPosition: $request->targetPosition,
            ));

            return new Response($request->gameId);
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to use spell: ' . $e->getMessage());
        }
    }
}
