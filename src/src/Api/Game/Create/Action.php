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
            // Log the full error for debugging
            error_log('Game creation error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            
            // Get a more descriptive error message
            $errorMessage = $e->getMessage() ?: get_class($e);
            if ($e->getPrevious()) {
                $errorMessage .= ' (Caused by: ' . $e->getPrevious()->getMessage() . ')';
            }
            
            return new Error(Uuid::v7(), 'Game creation failed: ' . $errorMessage);
        }

        return new Response($request->gameId);
    }
}
