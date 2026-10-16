<?php

declare(strict_types=1);

namespace App\Api\Table\Leave;

use App\Api\Table\Tables;
use App\Game\GameLifecycle\LeaveTable;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Leaves a table before it starts; the host leaving closes it.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
        private Tables $tables,
        private LoggerInterface $logger,
    ) {}

    #[Route('/tables/{gameId}/leave', methods: ['POST'])]
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
        $isHost = $game->getHostAccountId() === $account->getId()->toString();

        try {
            $this->messageBus->dispatch(new LeaveTable($game->getGameId(), $account->getId()->toString()));
        } catch (\Throwable $e) {
            return Tables::refusal($e);
        }
        $this->logger->info('table.left', ['gameId' => $gameId, 'accountId' => $account->getId()->toString(), 'closed' => $isHost]);

        return new JsonResponse(['closed' => $isHost]);
    }
}
