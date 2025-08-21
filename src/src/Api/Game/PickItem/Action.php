<?php

declare(strict_types=1);

namespace App\Api\Game\PickItem;

use App\Api\Error;
use App\Game\Field\FieldPlace;
use App\Game\Item\Item;
use App\Game\Player\Error\InventoryFullException;
use App\Game\Player\Error\MissingKeyException;
use App\Game\Player\PickItem;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/pick-item', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            // Create the command with optional item replacement
            $command = new PickItem(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                position: FieldPlace::fromString($request->position),
                itemIdToReplace: $request->itemIdToReplace,
            );

            // Dispatch the command
            $item = $this->messageBus->dispatch($command);

            // Return a success response
            return new Response(
                gameId: $request->gameId,
                playerId: $request->playerId,
                item: $item,
                itemReplaced: $request->itemIdToReplace !== null,
            );
        } catch (InventoryFullException $e) {
            // Handle inventory full exception
            return new Response(
                gameId: $request->gameId,
                playerId: $request->playerId,
                item: $e->item,
                inventoryFull: true,
                itemCategory: $e->category->value,
                maxItemsInCategory: $e->maxItems,
                currentInventory: $e->currentInventory,
            );
        } catch (MissingKeyException $e) {
            // Handle missing key exception
            return new Response(
                gameId: $request->gameId,
                playerId: $request->playerId,
                missingKey: true,
                chestType: $e->chest->type->value,
            );
        } catch (\Throwable $e) {
            // Handle position validation and other argument errors
            return new Error(
                code: Uuid::v7(),
                message: $e->getMessage() ?: 'An error occurred while picking up the item',
            );
        }
    }
}
