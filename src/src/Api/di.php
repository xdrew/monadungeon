<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $di): void {
    $di->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->load(__NAMESPACE__ . '\\', __DIR__ . '/**/Action.php')
            ->tag('controller.service_arguments');
};
