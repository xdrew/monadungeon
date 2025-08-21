<?php

declare(strict_types=1);

namespace App\Api\Game\RotateTile;

use App\Api\Error;
use App\Game\Field\GetTile;
use App\Game\Field\RotateTile;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/rotate-tile', methods: ['POST'])]
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
            $tile = $messageBus->dispatch(new GetTile($request->tileId));
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Tile rotation failed: ' . $e->getMessage());
        }

        return new Response($tile);
    }
}
