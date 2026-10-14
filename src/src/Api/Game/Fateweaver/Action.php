<?php

declare(strict_types=1);

namespace App\Api\Game\Fateweaver;

use App\Api\Error;
use App\Game\Field\ChooseFateweaverItem;
use App\Game\Field\GetField;
use App\Game\Item\Item;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Oracle "Fateweaver": choose which of the two drawn tokens is placed on the new room.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/fateweaver', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new ChooseFateweaverItem(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                itemId: $request->itemId,
            ));

            $field = $this->messageBus->dispatch(new GetField($request->gameId));
            $fieldPlace = null;
            $placed = null;
            foreach ($field->getItems() as $place => $item) {
                $item = Item::fromAnything($item);
                if ($item->itemId->equals($request->itemId)) {
                    $fieldPlace = $place;
                    $placed = $item;
                }
            }
            if ($fieldPlace === null || $placed === null) {
                return new Error($request->gameId, 'Failed to choose the token: it was not placed on the field');
            }

            return new Response($request->gameId, $fieldPlace, $placed);
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to choose the token: ' . $e->getMessage());
        }
    }
}
