<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\Listen;

use App\Api\Error;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\Field\Listen;
use App\Game\Item\Item;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Lady Lorraine "Listening": explore the adjacent tile just placed without entering it, for one move.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/listen', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new Listen(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                position: FieldPlace::fromString($request->position),
            ));

            $items = $this->messageBus->dispatch(new GetField($request->gameId))->getItems();
            $item = isset($items[$request->position]) ? Item::fromAnything($items[$request->position])->toArray() : null;

            return new Response($request->gameId, $request->position, $item);
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to listen: ' . $e->getMessage());
        }
    }
}
