<?php

declare(strict_types=1);

namespace App\Api\Game\PickTile;

use App\Api\Error;
use App\Game\Field\GetTile;
use App\Game\Field\PickTile;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/pick-tile', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, MessageBus $messageBus): Response|Error
    {
        try {
            $messageBus->dispatch(new PickTile(
                $request->gameId,
                $request->tileId,
                $request->playerId,
                $request->turnId,
                $request->requiredOpenSide,
                $request->fieldPlace,
            ));
            $tile = $messageBus->dispatch(new GetTile($request->tileId));
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Tile pick failed ' . $e::class . ' ' . $e->getMessage());
        }

        return new Response($tile);
    }
}
