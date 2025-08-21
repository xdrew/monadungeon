<?php

declare(strict_types=1);

namespace App\Api\Testing\ToggleTestMode;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $enabled,
    ) {}
}
