<?php

declare(strict_types=1);

namespace App\Infrastructure\Push;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $di): void {
    $di->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->set(MercurePublisher::class)
            ->arg('$hubUrl', '%env(MERCURE_URL)%')
            ->arg('$jwtSecret', '%env(MERCURE_JWT_SECRET)%')
        ->alias(Publisher::class, MercurePublisher::class);
};
