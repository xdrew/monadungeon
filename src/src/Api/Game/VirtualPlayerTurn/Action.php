<?php

declare(strict_types=1);

namespace App\Api\Game\VirtualPlayerTurn;

use App\Api\Error;
use App\Game\AI\SmartVirtualPlayer;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class Action
{
    public function __construct(
        private SmartVirtualPlayer $virtualPlayer,
    ) {}

    #[Route('/game/virtual-player-turn', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload]
        Request $request,
    ): Response|Error {
        $actions = [];
        
        try {
            $gameId = Uuid::fromString($request->gameId);
            $playerId = Uuid::fromString($request->playerId);
            
            // Add debug action to track API call
            $actions[] = [
                'type' => 'api_debug',
                'details' => ['message' => 'API endpoint called', 'gameId' => $request->gameId, 'playerId' => $request->playerId],
                'timestamp' => time(),
            ];
            
            // Execute the virtual player's turn
            try {
                $aiActions = $this->virtualPlayer->executeTurn($gameId, $playerId);
                $actions = array_merge($actions, $aiActions);
            } catch (\Throwable $aiError) {
                $actions[] = [
                    'type' => 'ai_execution_error',
                    'details' => ['error' => $aiError->getMessage(), 'trace' => $aiError->getTraceAsString()],
                    'timestamp' => time(),
                ];
                // Don't rethrow - we want to see the error in the response
            }
            
            return new Response(
                gameId: $request->gameId,
                playerId: $request->playerId,
                actions: $actions,
                success: true,
            );
        } catch (\Throwable $e) {
            // If we can't even parse the request
            $actions[] = [
                'type' => 'api_error',
                'details' => ['error' => $e->getMessage()],
                'timestamp' => time(),
            ];
            
            return new Response(
                gameId: $request->gameId ?? 'unknown',
                playerId: $request->playerId ?? 'unknown',
                actions: $actions,
                success: false,
            );
        }
    }
}