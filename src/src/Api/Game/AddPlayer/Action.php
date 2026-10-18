<?php

declare(strict_types=1);

namespace App\Api\Game\AddPlayer;

use App\Account\CurrentAccount;
use App\Api\Error;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\GetGame;
use App\Game\Player\GetPlayer;
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
        try {
            $game = $this->messageBus->dispatch(new GetGame($request->gameId));
        } catch (\Throwable) {
            $game = null;
        }
        // An online table is joined through /tables/{id}/join and gets its AI from the host only
        if ($game?->isOnline() === true) {
            return new Error(Uuid::v7(), 'table.use_join: join an online table through its invite', 409);
        }

        // A human seat belongs to the caller's account: its id and name, never what the body says — except the
        // later human seats of a hot-seat game, named guests. The AI keeps its own name and no account.
        $externalId = null;
        $username = $request->username;
        if (!$request->isAi) {
            $account = $this->currentAccount->get($httpRequest);
            if ($account === null) {
                return new Error(Uuid::v7(), 'account.required: sign in with a name first', 401);
            }
            $hotseat = $game !== null && $game->isHotseat();
            $seat = SeatIdentity::for(
                hotseat: $hotseat,
                accountId: $account->getId()->toString(),
                accountName: $account->getName(),
                seats: $game !== null && $hotseat ? $this->seatsOf($game->getPlayers()) : [],
                requestedName: $request->username,
            );
            $externalId = $seat->externalId;
            $username = $seat->username;
            if ($hotseat) {
                error_log('INFO game.hotseat_seat ' . json_encode(['gameId' => $request->gameId->toString(), 'playerId' => $request->playerId->toString(), 'guest' => $seat->guest]));
            }
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

    /**
     * @param list<Uuid> $playerIds
     * @return list<array{externalId: ?string, isAi: bool}>
     */
    private function seatsOf(array $playerIds): array
    {
        $seats = [];
        foreach ($playerIds as $playerId) {
            $player = $this->messageBus->dispatch(new GetPlayer($playerId));
            $seats[] = ['externalId' => $player->getExternalId(), 'isAi' => $player->isAi()];
        }

        return $seats;
    }
}
