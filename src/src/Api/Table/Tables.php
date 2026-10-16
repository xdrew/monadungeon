<?php

declare(strict_types=1);

namespace App\Api\Table;

use App\Account\Account;
use App\Account\CurrentAccount;
use App\Game\GameLifecycle\Error\CannotAddPlayerToAlreadyFullGame;
use App\Game\GameLifecycle\Error\CannotAddPlayerToAlreadyPreparedGame;
use App\Game\GameLifecycle\Error\TableRuleBroken;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GetGame;
use App\Game\Player\GetPlayer;
use App\Game\Player\Player;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Telephantast\MessageBus\MessageBus;

/**
 * What every table action shares: the caller's account, the table and its seats, the view the client gets,
 * and the refusals in one shape ({code, message}).
 */
final readonly class Tables
{
    public function __construct(
        private MessageBus $messageBus,
        private CurrentAccount $currentAccount,
    ) {}

    public static function refuse(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message], $status);
    }

    /**
     * A refusal from the domain, found anywhere in the exception chain the bus wraps it in.
     */
    public static function refusal(\Throwable $e): JsonResponse
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TableRuleBroken) {
                return self::refuse($cause->reason, $cause->getMessage(), 409);
            }
            if ($cause instanceof CannotAddPlayerToAlreadyFullGame) {
                return self::refuse('table.full', 'This table is full', 409);
            }
            if ($cause instanceof CannotAddPlayerToAlreadyPreparedGame) {
                return self::refuse('table.started', 'The table has already started', 409);
            }
            if ($cause instanceof \DomainException) {
                return self::refuse('table.refused', $cause->getMessage(), 409);
            }
        }

        return self::refuse('table.error', $e->getMessage(), 500);
    }

    public static function anonymous(): JsonResponse
    {
        return self::refuse('account.required', 'Sign in with a name first', 401);
    }

    public static function notFound(): JsonResponse
    {
        return self::refuse('table.not_found', 'No such table', 404);
    }

    public function account(Request $request): ?Account
    {
        return $this->currentAccount->get($request);
    }

    public function find(string $gameId): ?Game
    {
        if (!Uuid::isValid($gameId)) {
            return null;
        }

        try {
            $game = $this->messageBus->dispatch(new GetGame(Uuid::fromString($gameId)));
        } catch (\Throwable) {
            return null;
        }

        return $game->isOnline() ? $game : null;
    }

    /**
     * @return list<Player>
     */
    public function seats(Game $game): array
    {
        return array_map(fn(Uuid $id): Player => $this->messageBus->dispatch(new GetPlayer($id)), $game->getPlayers());
    }

    public function seatOf(Game $game, Account $account): ?Player
    {
        foreach ($this->seats($game) as $player) {
            if ($player->getExternalId() === $account->getId()->toString()) {
                return $player;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function view(Game $game, ?Account $account): array
    {
        $me = $account?->getId()->toString();
        $seats = [];
        $hostName = null;
        $mySeat = null;
        foreach ($this->seats($game) as $player) {
            $isHost = $player->getExternalId() !== null && $player->getExternalId() === $game->getHostAccountId();
            if ($isHost) {
                $hostName = $player->getUsername();
            }
            if ($me !== null && $player->getExternalId() === $me) {
                $mySeat = $player->getPlayerId()->toString();
            }
            $seats[] = [
                'playerId' => $player->getPlayerId()->toString(),
                'name' => $player->getUsername(),
                'hero' => $player->getChosenHero()?->value,
                'isAi' => $player->isAi(),
                'isHost' => $isHost,
                'isYou' => $me !== null && $player->getExternalId() === $me,
                'away' => $player->isAway(),
            ];
        }

        return [
            'gameId' => $game->getGameId()->toString(),
            'status' => $game->getStatus()->value,
            'endReason' => $game->getEndReason(),
            'seats' => $game->getSeats(),
            'taken' => \count($seats),
            'regent' => $game->isRegent(),
            'lordKarak' => $game->isLordKarak(),
            'randomHeroes' => $game->isRandomHeroes(),
            'turnSeconds' => $game->getTurnSeconds(),
            'hostName' => $hostName,
            'isHost' => $me !== null && $me === $game->getHostAccountId(),
            'mySeat' => $mySeat,
            'players' => $seats,
        ];
    }
}
