<?php

declare(strict_types=1);

namespace App\Api\Field\Tile\Pick;

use App\Api\Error;
use App\Game\Field\GetTile;
use App\Game\Field\PickTile;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/field/tile/pick', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, MessageBus $messageBus): Response|Error
    {
        try {
            $messageBus->dispatch(new PickTile(
                gameId: $request->gameId,
                tileId: $request->tileId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                requiredOpenSide: $request->requiredOpenSide,
            ));
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Tile pick failed: ' . $e);
        }
        $tile = $messageBus->dispatch(new GetTile($request->tileId));

        return new Response($request->tileId, $tile->getOrientation());
    }
}
