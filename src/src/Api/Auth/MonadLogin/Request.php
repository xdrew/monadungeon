<?php

declare(strict_types=1);

namespace App\Api\Auth\MonadLogin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        public string $walletAddress,
        #[Assert\NotBlank]
        public string $signature,
        public ?string $username = null,
    ) {}
}