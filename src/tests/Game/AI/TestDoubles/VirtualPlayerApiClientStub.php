<?php

declare(strict_types=1);

namespace App\Tests\Game\AI\TestDoubles;

use App\Game\Field\TileSide;
use App\Infrastructure\Uuid\Uuid;

/**
 * Test double for VirtualPlayerApiClient since it's final and can't be mocked
 */
class VirtualPlayerApiClientStub
{
    private array $responses = [];
    private array $callHistory = [];

    public function setResponse(string $method, array $response): void
    {
        $this->responses[$method] = $response;
    }

    public function getCallHistory(): array
    {
        return $this->callHistory;
    }

    public function pickTile(Uuid $gameId, Uuid $tileId, Uuid $playerId, Uuid $turnId, TileSide $requiredOpenSide, int $x, int $y): array
    {
        $this->callHistory[] = ['method' => 'pickTile', 'params' => func_get_args()];
        return $this->responses['pickTile'] ?? ['success' => true];
    }

    public function placeTileSequence(
        Uuid $gameId, 
        Uuid $playerId, 
        Uuid $turnId, 
        int $x, 
        int $y, 
        TileSide $requiredOpenSide, 
        int $moveFromX, 
        int $moveFromY
    ): array {
        $this->callHistory[] = ['method' => 'placeTileSequence', 'params' => func_get_args()];
        return $this->responses['placeTileSequence'] ?? ['success' => true, 'actions' => []];
    }

    public function movePlayer(
        Uuid $gameId,
        Uuid $playerId,
        Uuid $turnId,
        int $fromX,
        int $fromY,
        int $toX,
        int $toY,
        bool $usePortal
    ): array {
        $this->callHistory[] = ['method' => 'movePlayer', 'params' => func_get_args()];
        return $this->responses['movePlayer'] ?? ['success' => true, 'response' => []];
    }

    public function finalizeBattle(
        Uuid $gameId,
        Uuid $playerId,
        Uuid $turnId,
        Uuid $battleId,
        array $consumableIds,
        bool $pickUpItem
    ): array {
        $this->callHistory[] = ['method' => 'finalizeBattle', 'params' => func_get_args()];
        return $this->responses['finalizeBattle'] ?? ['success' => true];
    }

    public function pickItem(
        Uuid $gameId,
        Uuid $playerId,
        Uuid $turnId,
        int $x,
        int $y
    ): array {
        $this->callHistory[] = ['method' => 'pickItem', 'params' => func_get_args()];
        return $this->responses['pickItem'] ?? ['success' => true];
    }

    public function inventoryAction(
        Uuid $gameId,
        Uuid $playerId,
        Uuid $turnId,
        string $action,
        Uuid $itemId,
        ?Uuid $replaceWithId = null
    ): array {
        $this->callHistory[] = ['method' => 'inventoryAction', 'params' => func_get_args()];
        return $this->responses['inventoryAction'] ?? ['success' => true];
    }

    public function endTurn(Uuid $gameId, Uuid $playerId, Uuid $turnId): array
    {
        $this->callHistory[] = ['method' => 'endTurn', 'params' => func_get_args()];
        return $this->responses['endTurn'] ?? ['success' => true];
    }
}