<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\Field\TileSide;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Simulates API calls that a human player would make through the frontend
 * This ensures the AI follows the exact same game flow and all actions are properly recorded
 */
final readonly class VirtualPlayerApiClient
{
    public function __construct(
        private HttpKernelInterface $httpKernel,
    ) {}

    /**
     * Pick a tile from the deck (simulates frontend API call)
     */
    public function pickTile(Uuid $gameId, Uuid $tileId, Uuid $playerId, Uuid $turnId, TileSide $requiredOpenSide, int $x, int $y): array
    {
        $request = Request::create(
            uri: '/api/game/pick-tile',
            method: 'POST',
            content: json_encode([
                'gameId' => $gameId->toString(),
                'tileId' => $tileId->toString(),
                'playerId' => $playerId->toString(),
                'turnId' => $turnId->toString(),
                'requiredOpenSide' => $requiredOpenSide->value,
                'fieldPlace' => ['x' => $x, 'y' => $y],
            ])
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        
        return [
            'success' => in_array($response->getStatusCode(), [200, 201]),
            'action' => 'pick_tile',
            'tileId' => $tileId->toString(),
            'statusCode' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true)
        ];
    }

    /**
     * Place a tile on the field (simulates frontend API call)
     */
    public function placeTile(Uuid $gameId, Uuid $tileId, Uuid $playerId, Uuid $turnId, int $x, int $y): array
    {
        $request = Request::create(
            uri: '/api/game/place-tile',
            method: 'POST',
            content: json_encode([
                'gameId' => $gameId->toString(),
                'tileId' => $tileId->toString(),
                'playerId' => $playerId->toString(),
                'turnId' => $turnId->toString(),
                'fieldPlace' => "{$x},{$y}",
            ])
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        
        return [
            'success' => in_array($response->getStatusCode(), [200, 201]),
            'action' => 'place_tile',
            'position' => "{$x},{$y}",
            'tileId' => $tileId->toString(),
            'statusCode' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true)
        ];
    }

    /**
     * Rotate a tile (simulates frontend API call)
     */
    public function rotateTile(Uuid $gameId, Uuid $tileId, Uuid $playerId, Uuid $turnId, int $topSide, TileSide $requiredOpenSide): array
    {
        $request = Request::create(
            uri: '/api/game/rotate-tile',
            method: 'POST',
            content: json_encode([
                'gameId' => $gameId->toString(),
                'tileId' => $tileId->toString(),
                'playerId' => $playerId->toString(),
                'turnId' => $turnId->toString(),
                'topSide' => $topSide,
                'requiredOpenSide' => $requiredOpenSide->value,
            ])
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        
        return [
            'success' => $response->getStatusCode() === 200,
            'action' => 'rotate_tile',
            'tileId' => $tileId->toString(),
            'topSide' => $topSide,
            'statusCode' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true)
        ];
    }

    /**
     * Move player to a new position (simulates frontend API call)
     */
    public function movePlayer(Uuid $gameId, Uuid $playerId, Uuid $turnId, int $fromX, int $fromY, int $toX, int $toY, bool $isTilePlacementMove = false): array
    {
        $request = Request::create(
            uri: '/api/game/move-player',
            method: 'POST',
            content: json_encode([
                'gameId' => $gameId->toString(),
                'playerId' => $playerId->toString(),
                'turnId' => $turnId->toString(),
                'fromPosition' => "{$fromX},{$fromY}",
                'toPosition' => "{$toX},{$toY}",
                'ignoreMonster' => false,
                'isTilePlacementMove' => $isTilePlacementMove,
            ])
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        
        return [
            'success' => in_array($response->getStatusCode(), [200, 201]),
            'action' => 'move_player',
            'from' => "{$fromX},{$fromY}",
            'to' => "{$toX},{$toY}",
            'statusCode' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true)
        ];
    }

    /**
     * Pick up an item at current position (simulates frontend API call)
     */
    public function pickItem(Uuid $gameId, Uuid $playerId, Uuid $turnId, int $x, int $y, ?string $replaceItemId = null): array
    {
        $payload = [
            'gameId' => $gameId->toString(),
            'playerId' => $playerId->toString(),
            'turnId' => $turnId->toString(),
            'position' => "{$x},{$y}",
        ];
        
        if ($replaceItemId !== null) {
            $payload['itemIdToReplace'] = $replaceItemId;
        }
        
        $request = Request::create(
            uri: '/api/game/pick-item',
            method: 'POST',
            content: json_encode($payload)
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        
        return [
            'success' => in_array($response->getStatusCode(), [200, 201]),
            'action' => 'pick_item',
            'position' => "{$x},{$y}",
            'replaceItemId' => $replaceItemId,
            'statusCode' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true)
        ];
    }

    /**
     * Finalize a battle with optional consumables (simulates frontend API call)
     */
    public function finalizeBattle(Uuid $gameId, Uuid $playerId, Uuid $turnId, Uuid $battleId, array $selectedConsumableIds = [], bool $pickupItem = true, ?string $replaceItemId = null): array
    {
        $request = Request::create(
            uri: '/api/game/finalize-battle',
            method: 'POST',
            content: json_encode(array_filter([
                'gameId' => $gameId->toString(),
                'playerId' => $playerId->toString(),
                'turnId' => $turnId->toString(),
                'battleId' => $battleId->toString(),
                'selectedConsumableIds' => array_map(fn($id) => $id->toString(), $selectedConsumableIds),
                'pickupItem' => $pickupItem,
                'replaceItemId' => $replaceItemId,
            ], fn($value) => $value !== null))
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        
        return [
            'success' => $response->getStatusCode() === 200,
            'action' => 'finalize_battle',
            'battleId' => $battleId->toString(),
            'consumables' => $selectedConsumableIds,
            'pickupItem' => $pickupItem,
            'replaceItemId' => $replaceItemId,
            'statusCode' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true)
        ];
    }

    /**
     * Use a spell (simulates frontend API call)
     */
    public function useSpell(Uuid $gameId, Uuid $playerId, Uuid $turnId, Uuid $spellId, ?int $targetX = null, ?int $targetY = null): array
    {
        $payload = [
            'gameId' => $gameId->toString(),
            'playerId' => $playerId->toString(),
            'turnId' => $turnId->toString(),
            'spellId' => $spellId->toString(),
        ];
        
        if ($targetX !== null && $targetY !== null) {
            $payload['targetPosition'] = ['x' => $targetX, 'y' => $targetY];
        }

        $request = Request::create(
            uri: '/api/game/use-spell',
            method: 'POST',
            content: json_encode($payload)
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        
        return [
            'success' => $response->getStatusCode() === 200,
            'action' => 'use_spell',
            'spellId' => $spellId->toString(),
            'target' => $targetX !== null && $targetY !== null ? "{$targetX},{$targetY}" : null,
            'statusCode' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true)
        ];
    }

    /**
     * Perform inventory action - replace/rearrange items (simulates frontend API call)
     * Updated to match the actual API requirements
     */
    public function inventoryAction(Uuid $gameId, Uuid $playerId, Uuid $turnId, string $action, ?array $item = null, ?Uuid $replaceItemId = null): array
    {
        $payload = [
            'gameId' => $gameId->toString(),
            'playerId' => $playerId->toString(),
            'action' => $action,
        ];
        
        // For replace action, we need the full item object and itemIdToReplace
        if ($action === 'replace') {
            if ($item !== null) {
                $payload['item'] = $item;
            }
            if ($replaceItemId !== null) {
                $payload['itemIdToReplace'] = $replaceItemId->toString();
            }
        } elseif ($action === 'skip' && $item !== null) {
            $payload['item'] = $item;
        }

        $request = Request::create(
            uri: '/api/game/inventory-action',
            method: 'POST',
            content: json_encode($payload)
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        
        return [
            'success' => $response->getStatusCode() === 200,
            'action' => 'inventory_action',
            'inventoryAction' => $action,
            'item' => $item,
            'replaceItemId' => $replaceItemId?->toString(),
            'statusCode' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true)
        ];
    }

    /**
     * End the current turn (simulates frontend API call)
     */
    public function endTurn(Uuid $gameId, Uuid $playerId, Uuid $turnId): array
    {
        $request = Request::create(
            uri: '/api/game/end-turn',
            method: 'POST',
            content: json_encode([
                'gameId' => $gameId->toString(),
                'playerId' => $playerId->toString(),
                'turnId' => $turnId->toString(),
            ])
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        
        return [
            'success' => $response->getStatusCode() === 200,
            'action' => 'end_turn',
            'statusCode' => $response->getStatusCode(),
            'response' => json_decode($response->getContent(), true)
        ];
    }

    /**
     * Combined action: Pick and place a tile (like frontend does)
     */
    public function pickAndPlaceTile(Uuid $gameId, Uuid $playerId, Uuid $turnId, int $x, int $y, TileSide $requiredOpenSide): array
    {
        $tileId = Uuid::v7();
        
        // First pick the tile
        $pickResult = $this->pickTile($gameId, $tileId, $playerId, $turnId, $requiredOpenSide, $x, $y);
        
        // Then place it
        $placeResult = $this->placeTile($gameId, $tileId, $playerId, $turnId, $x, $y);
        
        return [
            'success' => true,
            'action' => 'pick_and_place_tile',
            'tileId' => $tileId->toString(),
            'position' => "{$x},{$y}",
            'pickResult' => $pickResult,
            'placeResult' => $placeResult
        ];
    }

    /**
     * Atomic Action 1: Place tile sequence (pick tile + place tile + move player)
     * Handles battle if it occurs and manages inventory automatically
     */
    public function placeTileSequence(Uuid $gameId, Uuid $playerId, Uuid $turnId, int $x, int $y, TileSide $requiredOpenSide, int $currentX, int $currentY): array
    {
        $tileId = Uuid::v7();
        $actions = [];
        
        // Step 1: Try to pick tile (may fail if there's already an unplaced tile)
        $pickResult = $this->pickTile($gameId, $tileId, $playerId, $turnId, $requiredOpenSide, $x, $y);
        $actions[] = ['step' => 'pick_tile', 'result' => $pickResult];
        
        // If pick failed due to unplaced tile, try to place with existing tile ID
        if (!$pickResult['success']) {
            $errorMessage = $pickResult['response']['message'] ?? '';
            if (strpos($errorMessage, 'CannotPlaceTileUntillPreviousTileIsNotPlaced') !== false) {
                // There's already a picked tile, let's try to place it with the current coordinates
                // We need to get the existing tile ID from the game state
                $actions[] = ['step' => 'skip_pick', 'reason' => 'Tile already picked, proceeding to place'];
                
                // Use the existing tile - in this case we'll try with a placeholder ID
                // This is a limitation of the current API design
                return ['success' => false, 'error' => 'Cannot place tile - there is already an unplaced tile', 'actions' => $actions];
            }
            return ['success' => false, 'error' => 'Failed to pick tile', 'actions' => $actions];
        }
        
        // Step 2: Place tile  
        $placeResult = $this->placeTile($gameId, $tileId, $playerId, $turnId, $x, $y);
        $actions[] = ['step' => 'place_tile', 'result' => $placeResult];
        
        if (!$placeResult['success']) {
            return ['success' => false, 'error' => 'Failed to place tile', 'actions' => $actions];
        }
        
        // Step 3: Move player to the new tile
        $moveResult = $this->movePlayer($gameId, $playerId, $turnId, $currentX, $currentY, $x, $y, true);
        $actions[] = ['step' => 'move_player', 'result' => $moveResult];
        
        if (!$moveResult['success']) {
            return ['success' => false, 'error' => 'Failed to move player', 'actions' => $actions];
        }
        
        // Step 4: Handle battle if it occurred - IMPORTANT: Pass battleInfo to response
        // The moveResult contains the full response including battleInfo
        $battleResult = $this->handleBattleIfExists($gameId, $playerId, $turnId, $moveResult['response']);
        if ($battleResult) {
            $actions[] = ['step' => 'handle_battle', 'result' => $battleResult];
            
            // Add battleInfo to the move result for EnhancedAIPlayer to detect
            if ($battleResult['battleOccurred']) {
                $moveResult['response']['battleInfo'] = [
                    'result' => $battleResult['battleResult'],
                    'monsterType' => $battleResult['monsterType'],
                    'reward' => $battleResult['reward'],
                    'position' => "{$x},{$y}",
                    'pickupSuccess' => $battleResult['pickupSuccess'] ?? false
                ];
            }
        }
        
        return [
            'success' => true,
            'action' => 'place_tile_sequence',
            'tileId' => $tileId->toString(),
            'position' => "{$x},{$y}",
            'actions' => $actions
        ];
    }

    /**
     * Atomic Action 2: Move player to existing tile
     * Handles battle if it occurs and manages inventory automatically
     */
    public function movePlayerToTile(Uuid $gameId, Uuid $playerId, Uuid $turnId, int $fromX, int $fromY, int $toX, int $toY): array
    {
        $actions = [];
        
        // Step 1: Move player
        $moveResult = $this->movePlayer($gameId, $playerId, $turnId, $fromX, $fromY, $toX, $toY, false);
        $actions[] = ['step' => 'move_player', 'result' => $moveResult];
        
        if (!$moveResult['success']) {
            return ['success' => false, 'error' => 'Failed to move player', 'actions' => $actions];
        }
        
        // Step 2: Handle battle if it occurred
        $battleResult = $this->handleBattleIfExists($gameId, $playerId, $turnId, $moveResult['response']);
        if ($battleResult) {
            $actions[] = ['step' => 'handle_battle', 'result' => $battleResult];
        }
        
        return [
            'success' => true,
            'action' => 'move_player_to_tile',
            'from' => "{$fromX},{$fromY}",
            'to' => "{$toX},{$toY}",
            'actions' => $actions
        ];
    }

    /**
     * Handle battle if it exists in the response
     * Picks up reward if battle was won
     */
    private function handleBattleIfExists(Uuid $gameId, Uuid $playerId, Uuid $turnId, ?array $response): ?array
    {
        if (!$response || !isset($response['battleInfo'])) {
            return null;
        }
        
        $battleInfo = $response['battleInfo'];
        $result = [
            'battleOccurred' => true,
            'battleResult' => $battleInfo['result'] ?? 'unknown',
            'monsterType' => $battleInfo['monsterType'] ?? 'unknown',
            'reward' => $battleInfo['reward'] ?? null
        ];
        
        // If battle was won and there's a reward, pick it up immediately
        if ($battleInfo['result'] === 'win' && isset($battleInfo['reward'])) {
            // Get position from battle info
            $position = $battleInfo['position'] ?? null;
            if ($position) {
                // Parse position string (format: "x,y")
                $coords = explode(',', $position);
                if (count($coords) === 2) {
                    $x = (int)$coords[0];
                    $y = (int)$coords[1];
                    
                    // Attempt to pick up the reward
                    $pickResult = $this->pickItem($gameId, $playerId, $turnId, $x, $y);
                    
                    $result['pickupAttempted'] = true;
                    $result['pickupSuccess'] = $pickResult['success'];
                    
                    if (!$pickResult['success']) {
                        $result['pickupError'] = $pickResult['response']['message'] ?? 'Unknown error';
                    }
                }
            }
        }
        
        return $result;
    }

}