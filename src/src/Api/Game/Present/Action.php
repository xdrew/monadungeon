<?php

declare(strict_types=1);

namespace App\Api\Game\Present;

use App\Api\Table\Tables;
use App\Game\Player\MarkSeatBack;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * "I am here": the caller's seat at an online table is no longer away (the game page calls it on opening,
 * the away plaque on "Take my seat back").
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
        private Tables $tables,
        private LoggerInterface $logger,
    ) {}

    #[Route('/game/{gameId}/present', methods: ['POST'])]
    public function __invoke(string $gameId, HttpRequest $httpRequest): JsonResponse
    {
        $account = $this->tables->account($httpRequest);
        if ($account === null) {
            return Tables::anonymous();
        }
        $game = $this->tables->find($gameId);
        $seat = $game !== null ? $this->tables->seatOf($game, $account) : null;
        if ($game === null || $seat === null) {
            return Tables::notFound();
        }
        $back = (bool) $this->messageBus->dispatch(new MarkSeatBack($seat->getPlayerId(), $game->getGameId()));
        if ($back) {
            $this->logger->info('seat.back', ['gameId' => $gameId, 'playerId' => $seat->getPlayerId()->toString()]);
        }

        return new JsonResponse(['playerId' => $seat->getPlayerId()->toString(), 'back' => $back]);
    }
}
