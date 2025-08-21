<?php

declare(strict_types=1);

namespace App\Api\Game\PlaceTile;

use App\Api\Error;
use App\Game\Field\GetTile;
use App\Game\Field\PlaceTile;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/place-tile', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, MessageBus $messageBus): Response|Error
    {
        try {
            $messageBus->dispatch(new PlaceTile(
                $request->gameId,
                $request->tileId,
                $request->fieldPlace,
                $request->playerId,
                $request->turnId,
            ));
            $tile = $messageBus->dispatch(new GetTile($request->tileId));
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Tile placement failed: ' . $e->getMessage());
        }

        return new Response($tile);
    }
}
