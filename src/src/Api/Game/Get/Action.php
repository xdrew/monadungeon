<?php

declare(strict_types=1);

namespace App\Api\Game\Get;

use App\Api\Error;
use App\Game\Field\GetField;
use App\Game\GameLifecycle\Error\GameNotFoundException;
use App\Game\GameLifecycle\Game as GameLifecycleGame;
use App\Game\GameLifecycle\GetGame;
use App\Game\Item\Item;
use App\Game\Player\GetActivePlayers;
use App\Game\Player\GetPlayer;
use App\Game\Player\QueryPlayerInventory;
use App\Game\Turn\Repository\GameTurnRepository;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
        private GameTurnRepository $gameTurnRepository,
    ) {}

    #[Route('/game/{gameId}', methods: ['GET'])]
    public function __invoke(string $gameId): Response|Error
    {
        try {
            $gameUuid = Uuid::fromString($gameId);
            $game = $this->messageBus->dispatch(new GetGame(gameId: $gameUuid));

            if (!$game instanceof GameLifecycleGame) {
                throw new \RuntimeException('Unexpected response type from message bus');
            }

            // Get field data for the game
            $field = $this->messageBus->dispatch(new GetField(gameId: $gameUuid));

            // Get player data for the game
            $playerData = [];

            try {
                $playerIds = $this->messageBus->dispatch(new GetActivePlayers(gameId: $gameUuid));
                foreach ($playerIds as $playerId) {
                    // Get player's inventory
                    /** @var array{key: array<Item>, weapon: array<Item>, spell: array<Item>, treasure: array<Item>} $inventory */
                    $inventory = $this->messageBus->dispatch(new QueryPlayerInventory(
                        playerId: $playerId,
                        gameId: $gameUuid,
                    ));
                    $player = $this->messageBus->dispatch(new GetPlayer($playerId));

                    // Get player data including HP and defeated status
                    $playerData[$playerId->toString()] = [
                        'hp' => $player->getHP(),
                        'defeated' => $player->isDefeated(),
                        'inventory' => [
                            'keys' => $this->formatInventoryItems($inventory['key'] ?? []),
                            'weapons' => $this->formatInventoryItems($inventory['weapon'] ?? []),
                            'spells' => $this->formatInventoryItems($inventory['spell'] ?? []),
                            'treasures' => $this->formatInventoryItems($inventory['treasure'] ?? []),
                        ],
                    ];
                }
            } catch (\Throwable $e) {
                // Log the error for debugging
                error_log('Failed to get player data: ' . $e->getMessage());
                // If we can't get player data, continue without it
            }

            // Get recent turns for action log (last 2 turns)
            $recentTurns = [];
            try {
                $allTurns = $this->gameTurnRepository->getForApi($gameUuid);
                // Get the last 2 turns
                $recentTurns = array_slice($allTurns, -2);
            } catch (\Throwable $e) {
                // Log the error for debugging
                error_log('Failed to get turns data: ' . $e->getMessage());
                // Continue without turns data
            }

            // If game is not found, field will be null, that's fine
            return Response::fromGameLifecycleGame($game, $field, $playerData, $this->messageBus, $recentTurns);
        } catch (GameNotFoundException $e) {
            return new Error(Uuid::v7(), 'Game not found: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Failed to get game: ' . $e->getMessage());
        }
    }

    /**
     * Format inventory items for API response.
     *
     * @param array<array-key, Item|array<string, mixed>> $items Array of Item objects or arrays
     * @return array<int, array<string, mixed>>
     */
    private function formatInventoryItems(array $items): array
    {
        $formatted = [];
        foreach ($items as $item) {
            // Item::fromAnything handles both Item objects and arrays
            $item = Item::fromAnything($item);
            $formatted[] = [
                'itemId' => $item->itemId->toString(),
                'name' => $item->name->value,
                'type' => $item->type->value,
                'treasureValue' => $item->treasureValue,
                'guardDefeated' => $item->guardDefeated,
                'guardHP' => $item->guardHP,
            ];
        }

        return $formatted;
    }
}
