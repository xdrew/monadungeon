<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\Deck\GetDeck;
use App\Game\Field\Field;
use App\Game\Field\GetField;
use App\Game\Field\PickTile;
use App\Game\Field\PlaceTile;
use App\Game\Field\RotateTile;
use App\Game\Field\Tile;
use App\Game\Field\TileOrientation;
use App\Game\Field\TileSide;
use App\Game\GameLifecycle\GetGame;
use App\Game\Movement\Commands\MovePlayer;
use App\Game\Movement\GetPlayerPosition;
use App\Game\Player\GetPlayer;
use App\Game\Player\Player;
use App\Game\Turn\EndTurn;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\MessageBus\MessageBus;

/**
 * Virtual AI player that makes autonomous decisions
 */
final readonly class VirtualPlayer
{
    public function __construct(
        private MessageBus $messageBus,
        private VirtualPlayerStrategy $strategy,
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
            $currentTurn = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
            $field = $this->messageBus->dispatch(new GetField($gameId));
            $player = $this->messageBus->dispatch(new GetPlayer($playerId, $gameId));

            $actions[] = $this->createAction('ai_thinking', ['message' => 'Analyzing game state...']);

            // Check if player is stunned
            if ($player->isDefeated()) {
                $actions[] = $this->createAction('player_stunned', ['reason' => 'Skipping turn due to stun']);
                
                // End turn immediately
                $this->messageBus->dispatch(new EndTurn(
                    turnId: $currentTurn->getId(),
                    gameId: $gameId,
                    playerId: $playerId,
                ));
                
                $actions[] = $this->createAction('turn_ended', ['reason' => 'stunned']);
                return $actions;
            }

            // Phase 1: Pick and place tile (if needed)
            if ($this->needsToPickTile($field, $currentTurn)) {
                $tileActions = $this->handleTilePhase($gameId, $playerId, $currentTurn, $field);
                $actions = array_merge($actions, $tileActions);
            }

            // Phase 2: Move player
            $moveActions = $this->handleMovementPhase($gameId, $playerId, $currentTurn, $field, $player);
            $actions = array_merge($actions, $moveActions);

            // Phase 3: End turn
            $this->messageBus->dispatch(new EndTurn(
                turnId: $currentTurn->getId(),
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
                $currentTurn = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
                $this->messageBus->dispatch(new EndTurn(
                    turnId: $currentTurn->getId(),
                    gameId: $gameId,
                    playerId: $playerId,
                ));
            } catch (\Throwable $endTurnError) {
                // Log but don't throw - we've done our best
            }
        }

        return $actions;
    }

    /**
     * Handle tile picking and placement phase
     */
    private function handleTilePhase(Uuid $gameId, Uuid $playerId, $currentTurn, Field $field): array
    {
        $actions = [];

        try {
            // Get deck and pick a tile
            $deck = $this->messageBus->dispatch(new GetDeck($gameId));
            
            // For now, just get the next tile from deck - simplified approach
            $nextTile = $deck->getNextTile();
            
            $actions[] = $this->createAction('ai_decision', [
                'decision' => "Selected next available tile from deck"
            ]);

            // For simplicity, just end the turn for now - full tile implementation needs more work
            $actions[] = $this->createAction('ai_decision', [
                'decision' => "Simplified AI - ending turn after analysis"
            ]);

            // Choose placement position
            $availablePlaces = $field->getAvailablePlacesForPlayer($playerId);
            $chosenPosition = $this->strategy->chooseTilePlacement($selectedTile, $availablePlaces, $field, $playerId);
            
            $actions[] = $this->createAction('ai_decision', [
                'decision' => "Placing tile at position: {$chosenPosition}"
            ]);

            // Determine optimal rotation
            $optimalOrientation = $this->strategy->chooseTileOrientation($selectedTile, $chosenPosition, $field);
            
            if ($optimalOrientation !== $selectedTile->getOrientation()) {
                $this->messageBus->dispatch(new RotateTile(
                    tileId: $selectedTile->getId(),
                    gameId: $gameId,
                    playerId: $playerId,
                    turnId: $currentTurn->getId(),
                    topSide: $optimalOrientation->getTopSide(),
                    requiredOpenSide: 0,
                ));
                
                $actions[] = $this->createAction('tile_rotated', [
                    'tileId' => $selectedTile->getId()->toString(),
                    'orientation' => $optimalOrientation->value
                ]);
            }

            // Place the tile
            $this->messageBus->dispatch(new PlaceTile(
                tileId: $selectedTile->getId(),
                gameId: $gameId,
                playerId: $playerId,
                turnId: $currentTurn->getId(),
                fieldPlace: $chosenPosition,
            ));

            $actions[] = $this->createAction('tile_placed', [
                'position' => $chosenPosition->toString(),
                'tileId' => $selectedTile->getId()->toString()
            ]);

        } catch (\Throwable $e) {
            $actions[] = $this->createAction('ai_error', [
                'phase' => 'tile_placement',
                'error' => $e->getMessage()
            ]);
        }

        return $actions;
    }

    /**
     * Handle player movement phase
     */
    private function handleMovementPhase(Uuid $gameId, Uuid $playerId, $currentTurn, Field $field, Player $player): array
    {
        $actions = [];

        try {
            $currentPosition = $field->getPlayerPosition($playerId);
            $availableMoves = $field->getAvailableMovesForPlayer($playerId);
            
            if (empty($availableMoves)) {
                $actions[] = $this->createAction('ai_decision', ['decision' => 'No moves available']);
                return $actions;
            }

            $chosenMove = $this->strategy->chooseMovement($currentPosition, $availableMoves, $field, $player);
            
            $actions[] = $this->createAction('ai_decision', [
                'decision' => "Moving from {$currentPosition} to {$chosenMove}"
            ]);

            $this->messageBus->dispatch(new MovePlayer(
                gameId: $gameId,
                playerId: $playerId,
                turnId: $currentTurn->getId(),
                fromPosition: $currentPosition,
                toPosition: $chosenMove,
                ignoreMonster: false,
                isBattleReturn: false,
                isTilePlacementMove: false,
            ));

            $actions[] = $this->createAction('player_moved', [
                'from' => $currentPosition->toString(),
                'to' => $chosenMove->toString()
            ]);

            // Check if movement triggered a battle or item pickup
            // These will be handled by the existing game mechanics

        } catch (\Throwable $e) {
            $actions[] = $this->createAction('ai_error', [
                'phase' => 'movement',
                'error' => $e->getMessage()
            ]);
        }

        return $actions;
    }

    /**
     * Check if the virtual player needs to pick a tile
     */
    private function needsToPickTile(Field $field, $currentTurn): bool
    {
        // For simplicity, assume we always need to pick a tile in the virtual player's turn
        // In reality, this would check turn state and available tiles
        return true;
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