<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Account;

final readonly class AccountView
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    public static function of(Account $account): self
    {
        return new self($account->getId()->toString(), $account->getName());
    }
}
