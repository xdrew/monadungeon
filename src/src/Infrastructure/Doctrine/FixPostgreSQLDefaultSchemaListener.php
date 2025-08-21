<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

final class FixPostgreSQLDefaultSchemaListener
{
    /**
     * @throws Exception
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schemaManager = $args
            ->getEntityManager()
            ->getConnection()
            ->createSchemaManager();

        $schema = $args->getSchema();

        foreach ($schemaManager->listSchemaNames() as $namespace) {
            if (!$schema->hasNamespace($namespace)) {
                $schema->createNamespace($namespace);
            }
        }
    }
}
