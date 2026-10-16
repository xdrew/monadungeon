<?php

declare(strict_types=1);

namespace App\Api\Account\Guest;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name,
    ) {}
}
