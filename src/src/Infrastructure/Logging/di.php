<?php

declare(strict_types=1);

use App\Infrastructure\Logging\GameLogger;
use App\Infrastructure\Logging\GameLoggingMiddleware;
use App\Infrastructure\Logging\HttpGameLoggingMiddleware;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $di): void {
    $services = $di->services();

    // GameLogger service
    $services->set(GameLogger::class)
        ->arg('$logDirectory', param('kernel.logs_dir') . '/games')
        ->arg('$fallbackLogger', service('logger'))
        ->public();

    // Message Bus Middleware for logging game commands/queries
    $services->set(GameLoggingMiddleware::class)
        ->arg('$gameLogger', service(GameLogger::class))
        ->tag('telephantast.handler_middleware', ['priority' => 1000]) // High priority to run early
        ->public();

    // HTTP Event Subscriber for logging HTTP requests/responses
    $services->set(HttpGameLoggingMiddleware::class)
        ->arg('$gameLogger', service(GameLogger::class))
        ->tag('kernel.event_subscriber')
        ->public();
};
