<?php

declare(strict_types=1);

namespace App\Api\Game\VirtualPlayerTurn;

use App\Api\Error;
use App\Game\AI\EnhancedAIPlayer;
use App\Game\AI\AIPlayerManager;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enhanced Virtual Player Turn Action that uses the new AI system
 */
final readonly class ActionEnhanced
{
    public function __construct(
        private EnhancedAIPlayer $enhancedAIPlayer,
        private AIPlayerManager $aiPlayerManager,
    ) {}

    #[Route('/game/virtual-player-turn-enhanced', methods: ['POST'])]
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
                'details' => ['message' => 'Enhanced AI endpoint called', 'gameId' => $request->gameId, 'playerId' => $request->playerId],
                'timestamp' => time(),
            ];
            
            // Register AI player if not already registered
            $this->aiPlayerManager->registerAIPlayer($gameId, $playerId, 'balanced');
            
            // Execute the enhanced AI player's turn
            try {
                $success = $this->enhancedAIPlayer->executeTurn($gameId, $playerId);
                
                $actions[] = [
                    'type' => 'ai_turn_complete',
                    'details' => ['success' => $success, 'message' => $success ? 'Turn executed successfully' : 'Turn execution failed or not AI turn'],
                    'timestamp' => time(),
                ];
                
                // Get player stats for debugging
                $stats = $this->aiPlayerManager->getAIPlayerStats($gameId, $playerId);
                if (!empty($stats)) {
                    $actions[] = [
                        'type' => 'ai_stats',
                        'details' => [
                            'strategy' => $stats['strategyType'],
                            'turns_played' => $stats['turnCount'],
                        ],
                        'timestamp' => time(),
                    ];
                }
            } catch (\Throwable $aiError) {
                $actions[] = [
                    'type' => 'ai_execution_error',
                    'details' => ['error' => $aiError->getMessage(), 'trace' => $aiError->getTraceAsString()],
                    'timestamp' => time(),
                ];
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