<?php

declare(strict_types=1);

namespace App\Api\Table\Ai;

use App\Api\Table\Tables;
use App\Game\GameLifecycle\AddAiToTable;
use App\Infrastructure\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * The host seats the AI on a free seat.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
        private Tables $tables,
        private LoggerInterface $logger,
    ) {}

    #[Route('/tables/{gameId}/ai', methods: ['POST'])]
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

        $playerId = Uuid::v7();

        try {
            $this->messageBus->dispatch(new AddAiToTable($game->getGameId(), $account->getId()->toString(), $playerId));
        } catch (\Throwable $e) {
            return Tables::refusal($e);
        }
        $this->logger->info('table.joined', ['gameId' => $gameId, 'playerId' => $playerId->toString(), 'ai' => true]);

        return new JsonResponse(['playerId' => $playerId->toString()], 201);
    }
}
