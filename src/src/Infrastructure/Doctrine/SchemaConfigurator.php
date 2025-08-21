<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\Schema;

interface SchemaConfigurator
{
    public function configureSchema(Schema $schema): void;
}
