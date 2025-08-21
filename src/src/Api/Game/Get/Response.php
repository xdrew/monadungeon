<?php

declare(strict_types=1);

namespace App\Api\Game\Get;

use App\Game\Deck\Deck;
use App\Game\Deck\GetDeck;
use App\Game\Field\Field;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\GameLifecycle\Game as GameLifecycleGame;
use App\Game\Movement\GetAllPlayerPositions;
use App\Game\Player\GetPlayer;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\MessageBus\MessageBus;

final readonly class Response
{
    /**
     * @param array<string, mixed> $state
     * @param array<int, array<string, mixed>> $players
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $turns
     */
    private function __construct(
        public string $gameId,
        public ?\DateTimeImmutable $createdAt,
        public array $state,
        public array $players,
        public array $settings,
        public ?array $field = null,
        public array $turns = [],
    ) {}

    /**
     * Create a Response from a GameLifecycleGame and Field.
     *
     * @param GameLifecycleGame $game The game
     * @param Field|null $field The field data
     * @param array<string, array<string, mixed>> $playerData Additional player data
     * @param MessageBus|null $messageBus Optional message bus for player data retrieval
     * @param array<int, array<string, mixed>> $recentTurns Recent turns for action log
     */
    public static function fromGameLifecycleGame(
        GameLifecycleGame $game,
        ?Field $field = null,
        array $playerData = [],
        ?MessageBus $messageBus = null,
        array $recentTurns = [],
    ): self {
        $currentPlayerId = $game->getCurrentPlayerId() ?: null;

        // Get deck information if message bus is available
        $deckInfo = null;
        if ($messageBus !== null) {
            try {
                $deck = $messageBus->dispatch(new GetDeck($game->getGameId()));
                $deckInfo = [
                    'remainingTiles' => $deck->getTilesRemainingCount(),
                    'isEmpty' => $deck->isEmpty(),
                ];
            } catch (\Throwable) {
                // If deck information is not available, set default values
                $deckInfo = [
                    'remainingTiles' => 0,
                    'isEmpty' => true,
                ];
            }
        }

        $availablePlaces = $currentPlayerId ? $field?->getAvailablePlacesForPlayer(new GetAvailablePlacesForPlayer($game->getGameId(), $currentPlayerId, $messageBus)) : null;

        // Get deck info
        $deckIsEmpty = $deckInfo['isEmpty'] ?? false;

        // Get all placed tile positions as a set for fast lookup
        $placedTiles = $field ? $field->getPlacedTiles() : [];
        $placedTilePositions = array_flip(array_map('strval', array_keys($placedTiles)));

        if ($availablePlaces !== null && $deckIsEmpty) {
            // Filter moveTo to only include positions that already have a tile
            $moveToPlaces = $availablePlaces['moveTo'] ?? [];
            if (\is_array($moveToPlaces)) {
                $availablePlaces['moveTo'] = array_values(array_filter(
                    $moveToPlaces,
                    static fn(string $pos) => isset($placedTilePositions[$pos]),
                ));
            }
            // placeTile should already be empty if deck is empty, but you can force it:
            $availablePlaces['placeTile'] = [];
        }

        // Format turns data
        $formattedTurns = self::formatTurns($recentTurns);

        return new self(
            gameId: (string) $game->getGameId(),
            createdAt: null, // If createdAt is not available on GameLifecycleGame
            state: [
                'status' => $game->getStatus()->value,
                'turn' => $game->getCurrentTurnNumber(),
                'currentPlayerId' => (string) $currentPlayerId,
                'currentTurnId' => $game->getCurrentTurnId() ? (string) $game->getCurrentTurnId() : null,
                'availablePlaces' => $availablePlaces,
                'lastBattleInfo' => $field?->getLastBattleInfo(),
                'deck' => $deckInfo, // Add deck information to state
            ],
            players: self::formatGameLifecyclePlayers($game->getPlayers(), $playerData, $messageBus),
            settings: [], // Add any settings if available in GameLifecycleGame
            field: $field ? self::formatFieldData($field, $messageBus) : null,
            turns: $formattedTurns,
        );
    }

    /**
     * @param array<array-key, Uuid> $playerIds
     * @param array<string, array<string, mixed>> $playerData Additional player data
     * @param MessageBus|null $messageBus Optional message bus for player data retrieval
     * @return array<int, array<string, mixed>>
     */
    private static function formatGameLifecyclePlayers(
        array $playerIds,
        array $playerData = [],
        ?MessageBus $messageBus = null,
    ): array {
        // Format basic player information
        $formattedPlayers = [];
        foreach ($playerIds as $playerId) {
            $playerFormattedData = [
                'id' => (string) $playerId,
            ];

            // Add player data from the provided playerData array
            $playerIdStr = $playerId->toString();
            if (isset($playerData[$playerIdStr])) {
                if (isset($playerData[$playerIdStr]['hp'])) {
                    $playerFormattedData['hp'] = $playerData[$playerIdStr]['hp'];
                }
                if (isset($playerData[$playerIdStr]['defeated'])) {
                    $playerFormattedData['defeated'] = $playerData[$playerIdStr]['defeated'];
                }
                // Add inventory data if available
                if (isset($playerData[$playerIdStr]['inventory'])) {
                    $playerFormattedData['inventory'] = $playerData[$playerIdStr]['inventory'];
                }
                // Add externalId if available
                if (isset($playerData[$playerIdStr]['externalId'])) {
                    $playerFormattedData['externalId'] = $playerData[$playerIdStr]['externalId'];
                }
            }

            // If we have no player health data and a message bus is provided, try to get detailed player information
            if (!isset($playerFormattedData['hp']) && $messageBus !== null) {
                try {
                    $player = $messageBus->dispatch(new GetPlayer($playerId));
                    $playerFormattedData['hp'] = $player->getHP();
                    $playerFormattedData['defeated'] = $player->isDefeated();
                    $playerFormattedData['externalId'] = $player->getExternalId();
                } catch (\Throwable) {
                    // Unable to get player data, continue with what we have
                }
            }

            // Default values if we couldn't get player data
            if (!isset($playerFormattedData['hp'])) {
                $playerFormattedData['hp'] = 5; // Default starting HP
            }
            if (!isset($playerFormattedData['defeated'])) {
                $playerFormattedData['defeated'] = false;
            }
            // Add empty inventory if no inventory data is available
            if (!isset($playerFormattedData['inventory'])) {
                $playerFormattedData['inventory'] = [
                    'keys' => [],
                    'weapons' => [],
                    'spells' => [],
                    'treasures' => [],
                ];
            }

            $formattedPlayers[] = $playerFormattedData;
        }

        return $formattedPlayers;
    }

    /**
     * Formats the field data for API response.
     *
     * @return array<string, mixed>
     */
    private static function formatFieldData(Field $field, ?MessageBus $messageBus = null): array
    {
        // Get basic field data
        $tiles = [];
        $placedTiles = $field->getPlacedTiles();

        // Get tile features map if messageBus is available
        $tileFeatures = $messageBus ? $field->getTileFeatures($messageBus) : [];

        // Min/max coordinates for calculating field size
        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = PHP_INT_MIN;

        foreach ($placedTiles as $coordinates => $tileId) {
            [$x, $y] = explode(',', $coordinates);
            $x = (int) $x;
            $y = (int) $y;

            // Update min/max coordinates
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);

            // Add tile data
            $tileData = [
                'x' => $x,
                'y' => $y,
                'tileId' => (string) $tileId,
                'position' => $coordinates,
            ];

            // Add features if available
            if (isset($tileFeatures[$coordinates])) {
                $tileData['features'] = array_map(static fn($feature) => $feature->value, $tileFeatures[$coordinates]);
            } else {
                $tileData['features'] = [];
            }

            $tiles[] = $tileData;
        }

        // Get player positions from Movement context
        $playerPositions = [];
        if ($messageBus !== null) {
            $movement = $messageBus->dispatch(new GetAllPlayerPositions($field->getGameId()));
            foreach ($movement as $playerId => $position) {
                $playerPositions[$playerId] = $position->toString();
            }
        }

        // Get available moves and places
        $availableFieldPlaces = [];
        foreach ($field->getAvailableFieldPlaces() as $place) {
            $availableFieldPlaces[] = $place->toString();
        }

        // Calculate field boundaries
        $size = [
            'width' => $maxX - $minX + 1,
            'height' => $maxY - $minY + 1,
            'minX' => $minX,
            'minY' => $minY,
            'maxX' => $maxX,
            'maxY' => $maxY,
        ];

        // Get tile orientations
        $tileOrientations = [];
        foreach ($field->getTileOrientations() as $position => $orientation) {
            $tileOrientations[$position] = $orientation;
        }

        // Get room tiles
        $roomFieldPlaces = [];
        foreach ($field->getRoomFieldPlaces() as $place) {
            $roomFieldPlaces[] = $place->toString();
        }

        // Get healing fountain positions
        $healingFountainPositions = [];
        foreach ($field->getHealingFountainPositions() as $place) {
            $healingFountainPositions[] = $place->toString();
        }

        return [
            'tiles' => $tiles,
            'playerPositions' => $playerPositions,
            'availablePlaces' => $availableFieldPlaces,
            'size' => $size,
            'tileOrientations' => $tileOrientations,
            'roomFieldPlaces' => $roomFieldPlaces,
            'items' => $field->getItems(),
            'healingFountainPositions' => $healingFountainPositions,
        ];
    }

    /**
     * Format turns data for the API response.
     *
     * @param array<int, array<string, mixed>> $turns
     * @return array<int, array<string, mixed>>
     */
    private static function formatTurns(array $turns): array
    {
        $formattedTurns = [];
        
        foreach ($turns as $turn) {
            // Parse actions JSON if it's a string
            $actions = $turn['actions'] ?? [];
            if (is_string($actions)) {
                $actions = json_decode($actions, true) ?? [];
            }
            
            $formattedTurns[] = [
                'turnId' => $turn['turn_id'] ?? null,
                'turnNumber' => $turn['turn_number'] ?? 0,
                'playerId' => $turn['player_id'] ?? null,
                'actions' => $actions,
                'startTime' => $turn['start_time'] ?? null,
                'endTime' => $turn['end_time'] ?? null,
            ];
        }
        
        return $formattedTurns;
    }
}
