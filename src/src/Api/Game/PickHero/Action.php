<?php

declare(strict_types=1);

namespace App\Api\Game\PickHero;

use App\Api\Error;
use App\Game\GameLifecycle\GetGame;
use App\Game\Player\HeroType;
use App\Game\Player\PickHero;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
    ) {}

    #[Route('/game/pick-hero', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            $game = $this->messageBus->dispatch(new GetGame($request->gameId));
            if (!$game->getStatus()->isPreparing()) {
                return new Error($request->gameId, 'Hero can only be picked before the game starts');
            }

            $this->messageBus->dispatch(new PickHero(
                gameId: $request->gameId,
                playerId: $request->playerId,
                hero: HeroType::from($request->hero),
            ));

            return new Response($request->gameId, $request->playerId, $request->hero);
        } catch (\Throwable $e) {
            return new Error($request->gameId, 'Failed to pick hero: ' . $e->getMessage(), $e instanceof \DomainException || $e->getPrevious() instanceof \DomainException ? 409 : 500);
        }
    }
}
