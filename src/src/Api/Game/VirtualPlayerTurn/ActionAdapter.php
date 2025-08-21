<?php

declare(strict_types=1);

namespace App\Api\Game\VirtualPlayerTurn;

use App\Api\Error;
use App\Game\AI\SmartVirtualPlayer;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API endpoint for executing virtual player turns
 */
final readonly class ActionAdapter
{
    public function __construct(
        private SmartVirtualPlayer $smartVirtualPlayer,
    ) {}

    #[Route('/game/virtual-player-turn', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload]
        Request $request,
    ): Response|Error {
        return $this->executeSmartAI($request);
    }

    /**
     * Execute turn using Smart AI with strategy support
     */
    private function executeSmartAI(Request $request): Response|Error
    {
        $actions = [];
        
        try {
            $gameId = Uuid::fromString($request->gameId);
            $playerId = Uuid::fromString($request->playerId);
            
            // Determine strategy from request (aggressive, balanced, defensive)
            $strategy = $request->strategy ?? 'balanced';
            
            $actions[] = [
                'type' => 'api_debug',
                'details' => [
                    'message' => 'Smart AI executing',
                    'gameId' => $request->gameId,
                    'playerId' => $request->playerId,
                    'strategy' => $strategy
                ],
                'timestamp' => time(),
            ];
            
            try {
                $aiActions = $this->smartVirtualPlayer->executeTurn($gameId, $playerId, $strategy);
                $actions = array_merge($actions, $aiActions);
                
                $actions[] = [
                    'type' => 'ai_turn_complete',
                    'details' => [
                        'ai_type' => 'smart',
                        'strategy' => $strategy,
                        'message' => 'AI turn complete',
                    ],
                    'timestamp' => time(),
                ];
                
            } catch (\Throwable $aiError) {
                $actions[] = [
                    'type' => 'ai_execution_error',
                    'details' => [
                        'error' => $aiError->getMessage(),
                        'ai_type' => 'smart',
                        'strategy' => $strategy,
                    ],
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