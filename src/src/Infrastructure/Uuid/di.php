<?php

declare(strict_types=1);

namespace App\Infrastructure\Uuid;

use App\Infrastructure\Uuid\DoctrineDBAL\UuidArrayJsonType;
use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\SymfonySerializer\UuidNormalizer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $di): void {
    $di->extension('doctrine', [
        'dbal' => [
            'types' => [
                UuidType::class => UuidType::class,
                UuidArrayJsonType::class => UuidArrayJsonType::class,
            ],
        ],
    ]);

    $di->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->set(UuidNormalizer::class);
};
