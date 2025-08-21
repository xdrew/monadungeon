<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\Field\GetField;
use App\Game\GameLifecycle\GetGame;
use App\Game\Player\GetPlayer;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\MessageBus\MessageBus;

/**
 * Simplified Virtual AI player that just ends turns
 */
final readonly class VirtualPlayerSimple
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    /**
     * Execute a complete turn for the virtual player
     */
    public function executeTurn(Uuid $gameId, Uuid $playerId): array
    {
        $actions = [];
        
        try {
            // Get current game state
            $game = $this->messageBus->dispatch(new GetGame($gameId));
            $currentTurnId = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
            $field = $this->messageBus->dispatch(new GetField($gameId));
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));

            $actions[] = $this->createAction('ai_thinking', ['message' => 'Analyzing game state...']);

            // Check if we have a valid turn ID
            if (!$currentTurnId) {
                $actions[] = $this->createAction('ai_error', [
                    'error' => 'No current turn found',
                    'decision' => 'Cannot execute turn without turn ID'
                ]);
                return $actions;
            }

            // Check if player is stunned
            if ($player->isDefeated()) {
                $actions[] = $this->createAction('player_stunned', ['reason' => 'Skipping turn due to stun']);
                
                // End turn immediately
                $this->messageBus->dispatch(new EndTurn(
                    turnId: $currentTurnId,
                    gameId: $gameId,
                    playerId: $playerId,
                ));
                
                $actions[] = $this->createAction('turn_ended', ['reason' => 'stunned']);
                return $actions;
            }

            // Simple AI: just end turn after thinking
            $actions[] = $this->createAction('ai_decision', [
                'decision' => 'Simple AI - ending turn after analysis'
            ]);

            // End turn
            $this->messageBus->dispatch(new EndTurn(
                turnId: $currentTurnId,
                gameId: $gameId,
                playerId: $playerId,
            ));
            
            $actions[] = $this->createAction('turn_ended', ['decision' => 'Turn completed']);

        } catch (\Throwable $e) {
            $actions[] = $this->createAction('ai_error', [
                'error' => $e->getMessage(),
                'decision' => 'Ending turn due to error'
            ]);
            
            // Try to end turn gracefully
            try {
                $currentTurnId = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
                if ($currentTurnId) {
                    $this->messageBus->dispatch(new EndTurn(
                        turnId: $currentTurnId,
                        gameId: $gameId,
                        playerId: $playerId,
                    ));
                }
            } catch (\Throwable) {
                // Ignore if we can't end turn
            }
        }

        return $actions;
    }

    /**
     * Create a standardized action log entry
     */
    private function createAction(string $type, array $details = []): array
    {
        return [
            'type' => $type,
            'details' => $details,
            'timestamp' => time(),
        ];
    }
}