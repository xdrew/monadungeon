<?php

declare(strict_types=1);

namespace App\Api\Game\Create;

use App\Api\Error;
use App\Game\GameLifecycle\CreateGame;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, MessageBus $messageBus): Response|Error
    {
        try {
            $messageBus->dispatch(new CreateGame($request->gameId));
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Game creation failed: ' . $e->getMessage());
        }

        return new Response($request->gameId);
    }
}
