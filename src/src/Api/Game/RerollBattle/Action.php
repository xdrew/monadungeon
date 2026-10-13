<?php

declare(strict_types=1);

namespace App\Api\Game\RerollBattle;

use App\Api\Error;
use App\Game\Battle\RerollBattleDice;
use App\Game\Field\GetField;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Warrior "Double Attack": reroll the dice of the current battle once.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/reroll-battle', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $this->messageBus->dispatch(new RerollBattleDice(
                battleId: $request->battleId,
                gameId: $request->gameId,
                playerId: $request->playerId,
                turnId: $request->turnId,
            ));

            // The new BattleCompleted event refreshed the field's last battle info
            $field = $this->messageBus->dispatch(new GetField($request->gameId));
            $battleInfo = $field->getLastBattleInfo();

            if ($battleInfo !== null
                && isset($battleInfo['player'])
                && Uuid::fromString((string) $battleInfo['player'])->equals($request->playerId)) {
                /** @var array<string, mixed> $battleInfo */
                return new Response($request->battleId, $request->gameId, $battleInfo);
            }

            return new Response($request->battleId, $request->gameId);
        } catch (\Throwable $e) {
            return new Error(
                code: Uuid::v7(),
                message: $e->getMessage() ?: 'Battle reroll failed',
            );
        }
    }
}
