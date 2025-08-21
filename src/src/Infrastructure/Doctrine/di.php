<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\Migrations\Provider\SchemaProvider;
use Doctrine\ORM\Tools\ToolEvents;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $di, ContainerBuilder $containerBuilder): void {
    $di->extension('doctrine', [
        'dbal' => [
            'types' => [
                BooleanType::class => BooleanType::class,
                JsonType::class => JsonType::class,
            ],
        ],
    ]);
    $di->extension('doctrine_migrations', [
        'services' => [
            SchemaProvider::class => ConfigurableSchemaProvider::class,
        ],
    ]);

    $di->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->set(FixPostgreSQLDefaultSchemaListener::class)
            ->tag('doctrine.event_listener', ['event' => ToolEvents::postGenerateSchema])
        ->set(AggregateRootOptimisticLockListener::class)
        ->set(ConfigurableSchemaProvider::class)
            ->args([
                inline_service()->factory([service('doctrine.migrations.dependency_factory'), 'getSchemaProvider']),
                inline_service(SchemaConfiguratorChain::class)->args([
                    tagged_iterator(SchemaConfigurator::class),
                ]),
            ]);

    $containerBuilder->registerForAutoconfiguration(SchemaConfigurator::class)->addTag(SchemaConfigurator::class);
};
