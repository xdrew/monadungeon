<?php

declare(strict_types=1);

namespace App\Api\Game\Duel\ChooseOpponent;

use App\Api\Error;
use App\Game\Field\ChooseDuelOpponent;
use App\Game\Field\GetField;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Karak: Regent — the hero who activated a Battle Arena summons a rival; the response carries the challenger's roll.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/duel/choose-opponent', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new ChooseDuelOpponent(
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
                opponentId: $request->opponentId,
            ));

            $field = $this->messageBus->dispatch(new GetField($request->gameId));
            $battleInfo = $field->getLastBattleInfo();
            if ($battleInfo !== null && ($battleInfo['player'] ?? null) !== $request->playerId->toString()) {
                $battleInfo = null;
            }

            return new Response(
                gameId: $request->gameId,
                battleInfo: $battleInfo,
                duel: $field->getDuelInfo(),
            );
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to start the duel: ' . $e->getMessage());
        }
    }
}
