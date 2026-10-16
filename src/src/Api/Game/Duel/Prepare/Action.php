<?php

declare(strict_types=1);

namespace App\Api\Game\Duel\Prepare;

use App\Api\Error;
use App\Game\Duel\PrepareDuelSide;
use App\Game\Field\GetField;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Karak: Regent — a hero of a duel chooses his spells (and the Warlock's sacrifice) before the roll.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/duel/prepare', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new PrepareDuelSide(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                duelId: $request->duelId,
                consumableIds: array_values(array_map(static fn(mixed $id): string => (string) $id, $request->consumableIds)),
                sacrifice: $request->sacrifice,
            ));

            $field = $this->messageBus->dispatch(new GetField($request->gameId));

            return new Response(
                gameId: $request->gameId,
                duel: $field->getDuelInfo(),
            );
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to prepare the duel: ' . $e->getMessage());
        }
    }
}
