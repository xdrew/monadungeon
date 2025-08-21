<?php

declare(strict_types=1);

namespace App\Infrastructure\Time;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $di): void {
    $di->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->set(ClockInterface::class, WallClock::class);
};
