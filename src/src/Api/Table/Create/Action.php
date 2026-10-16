<?php

declare(strict_types=1);

namespace App\Api\Table\Create;

use App\Api\Table\Tables;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateTable;
use App\Infrastructure\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Opens a table; the host takes the first seat.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
        private Tables $tables,
        private LoggerInterface $logger,
    ) {}

    #[Route('/tables', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, HttpRequest $httpRequest): JsonResponse
    {
        $account = $this->tables->account($httpRequest);
        if ($account === null) {
            return Tables::anonymous();
        }

        $gameId = Uuid::v7();
        $playerId = Uuid::v7();

        try {
            // Two dispatches: the new table is findable only after its factory returns
            $this->messageBus->dispatch(new CreateTable(
                gameId: $gameId,
                hostAccountId: $account->getId()->toString(),
                seats: $request->seats,
                regent: $request->regent,
                lordKarak: $request->lordKarak,
                randomHeroes: $request->randomHeroes,
            ));
            $this->messageBus->dispatch(new AddPlayer(
                gameId: $gameId,
                playerId: $playerId,
                externalId: $account->getId()->toString(),
                username: $account->getName(),
            ));
        } catch (\Throwable $e) {
            return Tables::refusal($e);
        }
        $this->logger->info('table.created', [
            'gameId' => $gameId->toString(),
            'accountId' => $account->getId()->toString(),
            'seats' => $request->seats,
            'regent' => $request->regent,
            'lordKarak' => $request->lordKarak,
            'randomHeroes' => $request->randomHeroes,
        ]);

        return new JsonResponse(['gameId' => $gameId->toString(), 'playerId' => $playerId->toString()], 201);
    }
}
