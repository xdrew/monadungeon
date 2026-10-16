<?php

declare(strict_types=1);

namespace App\Account\Google;

use App\Account\Account;
use App\Account\AccountName;
use Psr\Log\LoggerInterface;

/**
 * What a Google sign-in does, by who is at this device and whom Google names:
 * - a guest whose Google account is free: the guest is kept with it (bound);
 * - the Google account already keeps this very account: nothing changes (bound);
 * - it keeps another account: this device signs in to that one, the guest stays as it was (signed_in);
 * - nobody here and a free Google account: a new account, named after the Google name (created).
 */
final readonly class GoogleSignIn
{
    public const string BOUND = 'bound';
    public const string SIGNED_IN = 'signed_in';
    public const string CREATED = 'created';
    private const array SUGGESTIONS = ['Wanderer', 'Torchbearer', 'Delver', 'Seeker', 'Lantern', 'Ember'];

    public function __construct(
        private GoogleAccounts $accounts,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{string, Account, bool} the outcome, the account, whether this device needs a new session for it
     */
    public function signIn(?Account $here, GoogleIdentity $google): array
    {
        $keeper = $this->accounts->findByGoogleSub($google->sub);
        if ($keeper !== null) {
            if ($here !== null && $keeper->getId()->equals($here->getId())) {
                return [self::BOUND, $here, false];
            }
            $this->logger->info('account.google_signed_in', ['accountId' => $keeper->getId()->toString(), 'switchedFrom' => $here?->getId()->toString()]);

            return [self::SIGNED_IN, $keeper, true];
        }

        if ($here !== null && $here->getGoogleSub() === null) {
            $this->accounts->bindGoogle($here, $google->sub, $google->email);
            $this->logger->info('account.google_bound', ['accountId' => $here->getId()->toString()]);

            return [self::BOUND, $here, false];
        }

        $created = $this->accounts->createForGoogle($this->nameFor($google), $google->sub, $google->email);
        $this->logger->info('account.google_created', ['accountId' => $created->getId()->toString()]);

        return [self::CREATED, $created, true];
    }

    private function nameFor(GoogleIdentity $google): string
    {
        $given = trim((string) preg_replace('/[^\p{L}\p{N} \-_.\']/u', '', (string) $google->givenName));

        try {
            return AccountName::normalize(mb_substr($given, 0, 24));
        } catch (\InvalidArgumentException) {
            return self::SUGGESTIONS[random_int(0, \count(self::SUGGESTIONS) - 1)] . ' ' . random_int(10, 99);
        }
    }
}
