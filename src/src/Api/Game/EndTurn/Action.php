<?php

declare(strict_types=1);

namespace App\Api\Game\EndTurn;

use App\Api\Error;
use App\Game\Turn\EndTurn;
use App\Game\Turn\Error\UnplacedTileException;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/end-turn', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload]
        Request $request,
        MessageBus $messageBus,
    ): Response|Error {
        try {
            // End the current turn
            $messageBus->dispatch(new EndTurn(
                turnId: $request->turnId,
                gameId: $request->gameId,
                playerId: $request->playerId,
            ));
        } catch (UnplacedTileException $e) {
            return new Error(
                Uuid::v7(), 
                $e->getMessage(),
                HttpResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Could not end turn: ' . $e->getMessage());
        }

        return new Response(
            gameId: $request->gameId,
            playerId: $request->playerId,
            turnId: $request->turnId,
        );
    }
}
