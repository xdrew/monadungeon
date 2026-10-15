<?php

declare(strict_types=1);

namespace App\Api\Game\Hero\LightningAttack;

use App\Api\Error;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\Movement\Commands\LightningAttack;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Baron Markul "Lightning Attack": teleport onto a monster and fight it, for all 4 moves.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/lightning-attack', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new LightningAttack(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                targetPosition: FieldPlace::fromString($request->targetPosition),
            ));

            // The roll of the fight that just started (like a move onto a monster)
            $battleInfo = $this->messageBus->dispatch(new GetField($request->gameId))->getLastBattleInfo();
            if ($battleInfo !== null && ($battleInfo['player'] ?? null) !== $request->playerId->toString()) {
                $battleInfo = null;
            }

            return new Response($request->gameId, $battleInfo);
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to strike: ' . $e->getMessage());
        }
    }
}
