<?php

declare(strict_types=1);

namespace App\Api\Game\Duel\TakeLoot;

use App\Api\Error;
use App\Game\Duel\TakeDuelLoot;
use App\Game\Field\GetField;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Karak: Regent — the human challenger who won the duel takes one item from the loser.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/duel/loot', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new TakeDuelLoot(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                duelId: $request->duelId,
                itemId: $request->itemId,
                replaceItemId: $request->replaceItemId,
            ));

            $field = $this->messageBus->dispatch(new GetField($request->gameId));

            return new Response(
                gameId: $request->gameId,
                duel: $field->getDuelInfo(),
            );
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to take the duel loot: ' . $e->getMessage());
        }
    }
}
