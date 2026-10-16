<?php

declare(strict_types=1);

namespace App\Api\Game\AddPlayer;

use App\Account\CurrentAccount;
use App\Api\Error;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\Player\HeroType;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
        private CurrentAccount $currentAccount,
    ) {}

    #[Route('/game/player', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, HttpRequest $httpRequest): Response|Error
    {
        // A human seat belongs to the caller's account: its id and name, never what the body says.
        // The AI keeps its own name and no account.
        $externalId = null;
        $username = $request->username;
        if (!$request->isAi) {
            $account = $this->currentAccount->get($httpRequest);
            if ($account === null) {
                return new Error(Uuid::v7(), 'account.required: sign in with a name first', 401);
            }
            $externalId = $account->getId()->toString();
            $username = $account->getName();
        }

        try {
            $this->messageBus->dispatch(new AddPlayer(
                gameId: $request->gameId,
                playerId: $request->playerId,
                externalId: $externalId,
                username: $username,
                isAi: $request->isAi,
                hero: $request->hero !== null ? HeroType::from($request->hero) : null,
            ));
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Failed to add player to game: ' . $e->getMessage());
        }

        return new Response($request->playerId);
    }
}
