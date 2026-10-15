<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\Retreat;

use App\Api\Error;
use App\Game\Field\ChooseRetreat;
use App\Game\Field\FieldPlace;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Baron Markul: retreat onto an opened tile after a Lightning Attack that was not won (ends the turn).
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/retreat', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new ChooseRetreat(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                position: FieldPlace::fromString($request->position),
            ));

            return new Response($request->gameId, $request->playerId, $request->position);
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to retreat: ' . $e->getMessage());
        }
    }
}
