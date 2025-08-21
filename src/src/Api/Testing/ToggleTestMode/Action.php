<?php

declare(strict_types=1);

namespace App\Api\Testing\ToggleTestMode;

use App\Api\Error;
use App\Game\Testing\TestMode;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class Action
{
    #[Route('/test/toggle-mode', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        // Only allow in test environment or when explicitly enabled
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        if ($appEnv !== 'test' && $appEnv !== 'dev') {
            return new Error(Uuid::v7(), 'Test mode only available in test/dev environment');
        }

        $testMode = TestMode::getInstance();

        if ($request->enabled) {
            $testMode->enable();
        } else {
            $testMode->disable();
        }

        return new Response($testMode->isEnabled());
    }
}
