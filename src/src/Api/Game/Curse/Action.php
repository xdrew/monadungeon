<?php

declare(strict_types=1);

namespace App\Api\Game\Curse;

use App\Api\Error;
use App\Game\GameLifecycle\CursePlayer;
use App\Game\GameLifecycle\GetGame;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Curse of Karak: the mummy slayer chooses who carries the curse token (no target = leave it where it is).
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/curse', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new CursePlayer(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                targetPlayerId: $request->targetPlayerId,
            ));

            $game = $this->messageBus->dispatch(new GetGame($request->gameId));

            return new Response(
                gameId: $request->gameId,
                cursedPlayerId: $game->getCursedPlayerId()?->toString(),
                kept: $request->targetPlayerId === null,
            );
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to place the curse: ' . $e->getMessage());
        }
    }
}
