<?php

declare(strict_types=1);

namespace App\Api\Game\GameTurns;

use App\Api\Error;
use App\Game\GameLifecycle\Error\GameNotFoundException;
use App\Game\Turn\Repository\GameTurnRepository;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Routing\Attribute\Route;

final readonly class Action
{
    public function __construct(
        private GameTurnRepository $gameTurnRepository,
    ) {}

    #[Route('/game/{gameId}/turns', methods: ['GET'])]
    public function __invoke(string $gameId): Response|Error
    {
        try {
            $gameUuid = Uuid::fromString($gameId);

            $gameTurns = $this->gameTurnRepository->getForApi($gameUuid);

            return Response::fromGameTurns($gameId, $gameTurns);
        } catch (GameNotFoundException $e) {
            return new Error(Uuid::v7(), 'Game not found: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Failed to get game turns: ' . $e->getMessage());
        }
    }
}
