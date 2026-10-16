<?php

declare(strict_types=1);

namespace App\Api\Account\Rename;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name,
    ) {}
}
