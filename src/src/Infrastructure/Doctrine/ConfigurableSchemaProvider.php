<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Provider\SchemaProvider;

final readonly class ConfigurableSchemaProvider implements SchemaProvider
{
    public function __construct(
        private SchemaProvider $schemaProvider,
        private SchemaConfigurator $schemaConfigurator,
    ) {}

    public function createSchema(): Schema
    {
        $schema = $this->schemaProvider->createSchema();
        $this->schemaConfigurator->configureSchema($schema);

        return $schema;
    }
}
