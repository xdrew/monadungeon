<?php

declare(strict_types=1);

namespace App\Api\Field\Tile\Place;

use App\Api\Error;
use App\Game\Field\GetField;
use App\Game\Field\PlaceTile;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/field/tile/place', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, MessageBus $messageBus): Response|Error
    {
        try {
            $messageBus->dispatch(new PlaceTile(
                gameId: $request->gameId,
                tileId: $request->tileId,
                fieldPlace: $request->fieldPlace,
                playerId: $request->playerId,
                turnId: $request->turnId,
            ));
        } catch (\Throwable) {
            return new Error(Uuid::v7(), 'Tile place failed', HttpResponse::HTTP_BAD_REQUEST);
        }

        $field = $messageBus->dispatch(new GetField($request->gameId));

        // Get field items with proper formatting
        $fieldItems = [];
        foreach ($field->getItems() as $position => $item) {
            $fieldItems[$position] = [
                'itemId' => $item->itemId->toString(),
                'name' => $item->name->value,
                'type' => $item->type->value,
                'treasureValue' => $item->treasureValue,
                'guardDefeated' => $item->guardDefeated,
                'guardHP' => $item->guardHP,
            ];
        }

        return new Response($request->tileId, $field->getAvailableFieldPlaces(), $fieldItems);
    }
}
