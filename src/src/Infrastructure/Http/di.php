<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Logging\ApiLoggingMiddleware;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $di): void {
    $di->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->set(SerializeJsonControllerResultListener::class)
        ->set(ApiLoggingMiddleware::class)
            ->arg('$apiLogger', service('monolog.logger.api'));
};
