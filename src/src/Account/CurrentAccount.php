<?php

declare(strict_types=1);

namespace App\Account;

use App\Account\Session\SessionCookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The account behind the session cookie of the current request (null when there is none), looked up once per request.
 */
final readonly class CurrentAccount
{
    private const string ATTRIBUTE = '_karak_account';

    public function __construct(
        private RequestStack $requestStack,
        private AccountService $accounts,
    ) {}

    public function get(?Request $request = null): ?Account
    {
        $request ??= $this->requestStack->getMainRequest();
        if ($request === null) {
            return null;
        }
        if ($request->attributes->has(self::ATTRIBUTE)) {
            $cached = $request->attributes->get(self::ATTRIBUTE);

            return $cached instanceof Account ? $cached : null;
        }
        $token = (string) $request->cookies->get(SessionCookie::NAME, '');
        $account = $token === '' ? null : $this->accounts->findByToken($token);
        if ($account !== null) {
            $this->accounts->touch($account);
        }
        $request->attributes->set(self::ATTRIBUTE, $account ?? false);

        return $account;
    }

    /** After a guest is created in this request, the rest of it sees the new account. */
    public function remember(Request $request, Account $account): void
    {
        $request->attributes->set(self::ATTRIBUTE, $account);
    }
}
