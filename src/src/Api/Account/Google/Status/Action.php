<?php

declare(strict_types=1);

namespace App\Api\Account\Google\Status;

use App\Account\Google\GoogleClientChooser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Whether "Keep with Google" / "Sign in with Google" can be offered on this server.
 */
final readonly class Action
{
    public function __construct(
        private GoogleClientChooser $google,
    ) {}

    #[Route('/account/google', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['available' => $this->google->client()->isAvailable(), 'fake' => $this->google->isFake()]);
    }
}
