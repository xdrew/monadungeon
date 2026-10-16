<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Account\CurrentAccount;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every game command names the seat it acts for (`playerId` in the JSON body): the caller must be allowed to
 * act for it (SeatGuard). Creating a game and joining one are not seat commands; the test mode has its own routes.
 */
final readonly class SeatGuardListener
{
    private const array EXEMPT = ['/api/game', '/api/game/player'];
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private Connection $connection,
        private CurrentAccount $currentAccount,
        private LoggerInterface $logger,
    ) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = rtrim($request->getPathInfo(), '/');
        if (!$event->isMainRequest()) {
            // the AI's own sub-requests (VirtualPlayerApiClient) run inside an already guarded request
            return;
        }
        if ($request->getMethod() !== 'POST' || !str_starts_with($path, '/api/game/') || \in_array($path, self::EXEMPT, true)) {
            return;
        }
        $body = json_decode($request->getContent(), true);
        $playerId = \is_array($body) && \is_string($body['playerId'] ?? null) ? $body['playerId'] : null;
        if ($playerId === null || preg_match(self::UUID, $playerId) !== 1) {
            return;
        }

        $seat = $this->connection->fetchAssociative(
            'select game_id, is_ai, external_id from player.player where player_id = :id',
            ['id' => $playerId],
        );
        if ($seat === false) {
            return; // an unknown seat: the command itself answers
        }
        $owners = array_values(array_filter(array_map('strval', $this->connection->fetchFirstColumn(
            'select external_id from player.player where game_id = :game and is_ai = false and external_id is not null',
            ['game' => $seat['game_id']],
        ))));
        $caller = $this->currentAccount->get($request)?->getId()->toString();
        $verdict = SeatGuard::decide(
            ['isAi' => (bool) $seat['is_ai'], 'externalId' => $seat['external_id'] !== null ? (string) $seat['external_id'] : null],
            $owners,
            $caller,
            str_ends_with($path, '/virtual-player-turn') || str_ends_with($path, '/virtual-player-turn-enhanced'),
        );
        if ($verdict === SeatGuard::ALLOWED) {
            return;
        }

        $this->logger->warning('seat.refused', [
            'path' => $path,
            'gameId' => (string) $seat['game_id'],
            'playerId' => $playerId,
            'accountId' => $caller,
            'verdict' => $verdict,
        ]);
        $event->setResponse(new JsonResponse([
            'code' => $verdict,
            'message' => $verdict === SeatGuard::NOT_AI ? 'Only an AI seat plays an AI turn' : 'This seat is not yours',
        ], 403));
    }
}
