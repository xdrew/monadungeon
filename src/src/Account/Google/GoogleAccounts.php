<?php

declare(strict_types=1);

namespace App\Account\Google;

use App\Account\Account;

/**
 * What the sign-in needs from the account store (AccountService; an in-memory one in the tests).
 */
interface GoogleAccounts
{
    public function findByGoogleSub(string $sub): ?Account;

    public function bindGoogle(Account $account, string $sub, ?string $email): void;

    public function createForGoogle(string $name, string $sub, ?string $email): Account;
}
