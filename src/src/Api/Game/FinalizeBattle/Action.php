<?php

declare(strict_types=1);

namespace App\Api\Game\FinalizeBattle;

use App\Api\Error;
use App\Game\Battle\FinalizeBattle;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\Player\GetPlayer;
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

            // Warrior "Reincarnation": tell the frontend to let the hero choose a healing fountain
            $player = $this->messageBus->dispatch(new GetPlayer($request->playerId));
            $awaitingReincarnation = $player->isAwaitingReincarnation();
            $healingFountainPositions = [];
            if ($awaitingReincarnation) {
                $field = $this->messageBus->dispatch(new GetField($request->gameId));
                $healingFountainPositions = array_map(
                    static fn(FieldPlace $place): string => $place->toString(),
                    $field->getHealingFountainPositions(),
                );
            }

            // Return success response
            return new Response(
                battleId: $request->battleId,
                gameId: $request->gameId,
                playerId: $request->playerId,
                finalTotalDamage: 0, // Will be updated by battle logic
                success: true,
                itemPickedUp: $pickupItem,
                awaitingReincarnation: $awaitingReincarnation,
                healingFountainPositions: $healingFountainPositions,
            );
        } catch (\Throwable $e) {
            return new Error(
                code: Uuid::v7(),
                message: $e->getMessage() ?: 'Battle finalization failed',
            );
        }
    }
}
