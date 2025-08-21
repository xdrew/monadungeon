<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\Schema;

final readonly class SchemaConfiguratorChain implements SchemaConfigurator
{
    /**
     * @param iterable<SchemaConfigurator> $schemaConfigurators
     */
    public function __construct(
        private iterable $schemaConfigurators,
    ) {}

    public function configureSchema(Schema $schema): void
    {
        foreach ($this->schemaConfigurators as $schemaConfigurator) {
            $schemaConfigurator->configureSchema($schema);
        }
    }
}
