<?php

declare(strict_types=1);

namespace App\Api\Game\FinalizeBattle;

use App\Api\Error;
use App\Game\Battle\FinalizeBattle;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/finalize-battle', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            // Ensure boolean values are properly converted
            $pickupItem = filter_var($request->pickupItem, FILTER_VALIDATE_BOOLEAN);

            // Create the command with properly converted parameters
            $command = new FinalizeBattle(
                battleId: $request->battleId,
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                selectedConsumableIds: $request->selectedConsumableIds,
                pickupItem: $pickupItem, // Already converted to boolean
                replaceItemId: $request->replaceItemId,
            );

            // Dispatch the command
            $this->messageBus->dispatch($command);

            // Return success response
            return new Response(
                battleId: $request->battleId,
                gameId: $request->gameId,
                playerId: $request->playerId,
                finalTotalDamage: 0, // Will be updated by battle logic
                success: true,
                itemPickedUp: $pickupItem,
            );
        } catch (\Throwable $e) {
            return new Error(
                code: Uuid::v7(),
                message: $e->getMessage() ?: 'Battle finalization failed',
            );
        }
    }
}
