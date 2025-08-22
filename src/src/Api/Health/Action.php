<?php

declare(strict_types=1);

namespace App\Api\Health;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class Action
{
    #[Route('/health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'healthy',
            'timestamp' => time(),
        ]);
    }
}