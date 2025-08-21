<?php

declare(strict_types=1);

namespace App\Api\Game\InventoryAction;

use App\Api\Error;
use App\Game\GameLifecycle\Error\GameNotFoundException;
use App\Game\Item\Item;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Player\ReplaceInventoryItem;
use App\Game\Player\SkipItemPickup;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/inventory-action', methods: ['POST'])]
    public function __invoke(Request $request): Response|Error
    {
        try {
            $content = json_decode($request->getContent(), true);
            if (!\is_array($content)) {
                return new Error(Uuid::v7(), 'Invalid request format');
            }

            // Validate required fields
            if (!isset($content['gameId']) || !\is_string($content['gameId'])) {
                return new Error(Uuid::v7(), 'Missing or invalid gameId');
            }
            if (!isset($content['playerId']) || !\is_string($content['playerId'])) {
                return new Error(Uuid::v7(), 'Missing or invalid playerId');
            }
            if (!isset($content['item']) || !\is_array($content['item'])) {
                return new Error(Uuid::v7(), 'Missing or invalid item data');
            }

            // Extract necessary params
            $gameId = Uuid::fromString($content['gameId']);
            $playerId = Uuid::fromString($content['playerId']);
            $action = isset($content['action']) && \is_string($content['action']) ? $content['action'] : null;

            if ($action === null || !\in_array($action, ['replace', 'skip'], true)) {
                return new Error(Uuid::v7(), 'Invalid inventory action. Must be "replace" or "skip".');
            }

            // Create the item object from the provided data
            /** @var array{name: string, type: string, guardHP?: int, treasureValue?: int, guardDefeated?: bool, itemId?: string} $itemData */
            $itemData = $content['item'];
            $item = $this->createItemFromData($itemData);

            if ($action === 'replace') {
                // We need an itemIdToReplace
                if (!isset($content['itemIdToReplace'])) {
                    return new Error(Uuid::v7(), 'Missing itemIdToReplace for replace action');
                }

                if (!\is_string($content['itemIdToReplace'])) {
                    return new Error(Uuid::v7(), 'itemIdToReplace must be a string');
                }

                $itemIdToReplace = Uuid::fromString($content['itemIdToReplace']);

                // Send the replace command
                $this->messageBus->dispatch(new ReplaceInventoryItem(
                    playerId: $playerId,
                    gameId: $gameId,
                    itemIdToReplace: $itemIdToReplace,
                    newItem: $item,
                ));
            } else {
                // Skip item action
                $this->messageBus->dispatch(new SkipItemPickup(
                    playerId: $playerId,
                    gameId: $gameId,
                    skippedItem: $item,
                ));
            }

            return new Response($gameId->toString(), $playerId->toString(), $action);
        } catch (GameNotFoundException $e) {
            return new Error(Uuid::v7(), 'Game not found: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Failed to process inventory action: ' . $e->getMessage());
        }
    }

    /**
     * Create an Item object from the provided data.
     * @param array{name: string, type: string, guardHP?: int, treasureValue?: int, guardDefeated?: bool, itemId?: string} $itemData
     */
    private function createItemFromData(array $itemData): Item
    {
        return new Item(
            name: ItemName::from($itemData['name']),
            type: ItemType::from($itemData['type']),
            guardHP: $itemData['guardHP'] ?? 0,
            treasureValue: $itemData['treasureValue'] ?? 0,
            guardDefeated: $itemData['guardDefeated'] ?? true,
            itemId: isset($itemData['itemId']) ? Uuid::fromString($itemData['itemId']) : null,
        );
    }
}
