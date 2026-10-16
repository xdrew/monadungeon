<?php

declare(strict_types=1);

namespace App\Api\Table\Join;

use App\Api\Table\Tables;
use App\Game\GameLifecycle\AddPlayer;
use App\Infrastructure\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Takes a free seat; an account that already sits at the table gets its seat back.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
        private Tables $tables,
        private LoggerInterface $logger,
    ) {}

    #[Route('/tables/{gameId}/join', methods: ['POST'])]
    public function __invoke(string $gameId, HttpRequest $httpRequest): JsonResponse
    {
        $account = $this->tables->account($httpRequest);
        if ($account === null) {
            return Tables::anonymous();
        }
        $game = $this->tables->find($gameId);
        if ($game === null) {
            return Tables::notFound();
        }
        $seat = $this->tables->seatOf($game, $account);
        if ($seat !== null) {
            return new JsonResponse(['playerId' => $seat->getPlayerId()->toString()]);
        }

        $playerId = Uuid::v7();

        try {
            $this->messageBus->dispatch(new AddPlayer(
                gameId: $game->getGameId(),
                playerId: $playerId,
                externalId: $account->getId()->toString(),
                username: $account->getName(),
            ));
        } catch (\Throwable $e) {
            return Tables::refusal($e);
        }
        $this->logger->info('table.joined', [
            'gameId' => $gameId,
            'playerId' => $playerId->toString(),
            'accountId' => $account->getId()->toString(),
        ]);

        return new JsonResponse(['playerId' => $playerId->toString()], 201);
    }
}
