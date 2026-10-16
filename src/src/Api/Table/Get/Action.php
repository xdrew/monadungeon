<?php

declare(strict_types=1);

namespace App\Api\Table\Get;

use App\Api\Table\Tables;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Attribute\Route;

final readonly class Action
{
    public function __construct(
        private Tables $tables,
    ) {}

    #[Route('/tables/{gameId}', methods: ['GET'])]
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

        return new JsonResponse($this->tables->view($game, $account));
    }
}
