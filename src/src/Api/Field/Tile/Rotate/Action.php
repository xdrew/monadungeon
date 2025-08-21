<?php

declare(strict_types=1);

namespace App\Api\Field\Tile\Rotate;

use App\Api\Error;
use App\Game\Field\GetTile;
use App\Game\Field\RotateTile;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/field/tile/rotate', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, MessageBus $messageBus): Response|Error
    {
        try {
            $messageBus->dispatch(new RotateTile(
                tileId: $request->tileId,
                topSide: $request->topSide,
                requiredOpenSide: $request->requiredOpenSide,
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
            ));
        } catch (\Throwable) {
            return new Error(Uuid::v7(), 'Tile pick failed');
        }

        $tile = $messageBus->dispatch(new GetTile($request->tileId));

        return new Response($request->tileId, $tile->getOrientation());
    }
}
