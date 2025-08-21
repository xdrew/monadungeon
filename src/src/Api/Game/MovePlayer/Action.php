<?php

declare(strict_types=1);

namespace App\Api\Game\MovePlayer;

use App\Api\Error;
use App\Game\Field\Field;
use App\Game\Field\GetField;
use App\Game\Item\Item;
use App\Game\Movement\Commands\MovePlayer;
use App\Game\Player\QueryPlayerInventory;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    #[Route('/game/move-player', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, MessageBus $messageBus): Response|Error
    {
        try {
            $messageBus->dispatch(new MovePlayer(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                fromPosition: $request->fromPosition,
                toPosition: $request->toPosition,
                ignoreMonster: $request->ignoreMonster,
                isBattleReturn: false,
                isTilePlacementMove: $request->isTilePlacementMove,
            ));

            // Fetch the field to check if a battle occurred
            $field = $messageBus->dispatch(new GetField($request->gameId));
            $battleInfo = $field->getLastBattleInfo();

            // Only return battle info if it's for the current player and position
            if ($battleInfo !== null
                && isset($battleInfo['player'])
                && Uuid::fromString((string) $battleInfo['player'])->equals($request->playerId)) {
                /** @var array<string, mixed> $battleInfo */
                return new Response($request->gameId, $battleInfo);
            }

            // Check if there's a pickable item at the destination
            $items = $field->getItems();
            $toPositionString = $request->toPosition->toString();

            if (isset($items[$toPositionString])) {
                $item = Item::fromAnything($items[$toPositionString]);

                // Check if the item is pickable (defeated guard or no guard)
                if ($item->guardDefeated || $item->guardHP === 0) {
                    // Get player inventory to check if they need keys for chests
                    $playerInventory = $messageBus->dispatch(new QueryPlayerInventory(
                        playerId: $request->playerId,
                        gameId: $request->gameId,
                    ));

                    // Check if item is worth picking up
                    $isWorthPickingUp = $this->isItemWorthPickingUp($item, $playerInventory);

                    // Only return item info if it's worth picking up
                    if ($isWorthPickingUp) {
                        // Build item info similar to battle info
                        $itemInfo = [
                            'position' => $toPositionString,
                            'item' => [
                                'name' => $item->name->value,
                                'type' => $item->type->value,
                                'treasureValue' => $item->treasureValue,
                                'itemId' => $item->itemId->toString(),
                                'guardDefeated' => $item->guardDefeated,
                                'guardHP' => $item->guardHP,
                            ],
                            'requiresKey' => \in_array($item->type->value, ['chest', 'ruby_chest'], true),
                            'hasKey' => ($playerInventory['keys'] ?? []) !== [],
                        ];

                        return new Response($request->gameId, null, $itemInfo);
                    }
                }
            }

            // If no battle or item info was found, just return the game ID
            return new Response($request->gameId);
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Player move failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array{weapons?: array<int, array{name: string}>, spells?: array<int, array{name: string}>, treasures?: array<int, array{name: string}>, keys?: array<int, array{name: string}>} $playerInventory
     */
    private function isItemWorthPickingUp(Item $item, array $playerInventory): bool
    {
        // Always pick up the dragon (ends game)
        if ($item->endsGame) {
            return true;
        }

        // Check if it's a chest that requires a key
        if (\in_array($item->type->value, ['chest', 'ruby_chest'], true)) {
            // Skip if player has no keys
            $keys = $playerInventory['keys'] ?? [];
            if (\count($keys) === 0) {
                return false;
            }
        }

        // Check for duplicates
        $weapons = $playerInventory['weapons'] ?? [];
        $spells = $playerInventory['spells'] ?? [];
        $treasures = $playerInventory['treasures'] ?? [];
        $keys = $playerInventory['keys'] ?? [];

        $existingItems = array_merge(
            $weapons,
            $spells,
            $treasures,
            $keys,
        );

        foreach ($existingItems as $existingItem) {
            if ($existingItem['name'] === $item->name->value) {
                // Skip duplicate items
                return false;
            }
        }

        return true;
    }
}
