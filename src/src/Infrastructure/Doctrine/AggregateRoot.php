<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\Version;

#[MappedSuperclass]
abstract class AggregateRoot
{
    #[Column(type: Types::INTEGER)]
    #[Version]
    private int $version = 1;
}
