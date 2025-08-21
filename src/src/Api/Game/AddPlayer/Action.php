<?php

declare(strict_types=1);

namespace App\Api\Game\AddPlayer;

use App\Api\Error;
use App\Game\GameLifecycle\AddPlayer;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/player', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new AddPlayer(
                gameId: $request->gameId,
                playerId: $request->playerId,
                externalId: $request->externalId,
                username: $request->username,
                walletAddress: $request->walletAddress,
            ));
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Failed to add player to game: ' . $e->getMessage());
        }

        return new Response($request->playerId);
    }
}
