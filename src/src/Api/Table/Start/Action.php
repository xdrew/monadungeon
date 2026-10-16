<?php

declare(strict_types=1);

namespace App\Api\Table\Start;

use App\Api\Table\Tables;
use App\Game\GameLifecycle\StartTable;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * The host starts the table.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
        private Tables $tables,
        private LoggerInterface $logger,
    ) {}

    #[Route('/tables/{gameId}/start', methods: ['POST'])]
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

        try {
            $this->messageBus->dispatch(new StartTable($game->getGameId(), $account->getId()->toString()));
        } catch (\Throwable $e) {
            return Tables::refusal($e);
        }
        $this->logger->info('table.started', ['gameId' => $gameId, 'seats' => \count($game->getPlayers())]);

        return new JsonResponse($this->tables->view($this->tables->find($gameId) ?? $game, $account));
    }
}
