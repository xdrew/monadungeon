<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Account;

final readonly class AccountView
{
    public function __construct(
        public string $id,
        public string $name,
        /**
         * Kept with Google: the address shown masked ("a…@gmail.com"); null for a guest.
         *
         * @var array{email: ?string}|null
         */
        public ?array $google = null,
    ) {}

    public static function of(Account $account): self
    {
        return new self(
            $account->getId()->toString(),
            $account->getName(),
            $account->getGoogleSub() !== null ? ['email' => self::mask($account->getGoogleEmail())] : null,
        );
    }

    private static function mask(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1) . '…@' . $domain;
    }
}
