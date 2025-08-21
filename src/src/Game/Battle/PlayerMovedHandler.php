<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Game\Field\GetField;
use App\Game\Item\Item;
use App\Game\Movement\Events\PlayerMoved;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\MessageBus\Handler\Mapping\Handler;
use Telephantast\MessageBus\MessageContext;

/**
 * Handles player movement events to initiate battles when stepping on monsters.
 */
final class PlayerMovedHandler
{
    #[Handler]
    public function __invoke(PlayerMoved $event, MessageContext $context): void
    {
        // Don't start battles for battle return movements
        if ($event->isBattleReturn) {
            return;
        }

        // Get field to check for monsters at destination
        $field = $context->dispatch(new GetField($event->gameId));
        $items = $field->getItems();
        $destinationKey = $event->toPosition->toString();

        // Check if there's a monster at the destination
        if (!isset($items[$destinationKey])) {
            return;
        }

        $item = Item::fromAnything($items[$destinationKey]);

        // Only start battle if it's a monster and not already defeated
        if (!$item->guardHP || $item->guardDefeated) {
            return;
        }

        // Get current turn ID
        $turnId = $context->dispatch(new GetCurrentTurn($event->gameId));
        if ($turnId === null) {
            // No active turn, can't start battle
            return;
        }

        // Start the battle
        $context->dispatch(new StartBattle(
            battleId: Uuid::v7(),
            gameId: $event->gameId,
            playerId: $event->playerId,
            turnId: $turnId,
            monster: $item,
            fromPosition: $event->fromPosition,
            toPosition: $event->toPosition,
        ));
    }
}
