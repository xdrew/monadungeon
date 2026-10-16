<?php

declare(strict_types=1);

namespace App\Tests\Account\Google;

use App\Account\Account;
use App\Account\Google\FakeGoogleClient;
use App\Account\Google\GoogleAccounts;
use App\Account\Google\GoogleIdentity;
use App\Account\Google\GoogleSignIn;
use App\Account\Google\GoogleSignInFailed;
use App\Api\Account\Google\OAuthCookie;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Keeping an account with Google and signing in with it: every case of who is at the device × whom Google
 * names, the ID-token claim checks, the fake provider and the return path.
 */
#[CoversClass(GoogleSignIn::class)]
#[CoversClass(GoogleIdentity::class)]
final class GoogleSignInTest extends TestCase
{
    private const string CLIENT = 'client-1.apps.googleusercontent.com';

    /** @var array<string, Account> by Google sub */
    public array $bySub = [];

    /** @var list<Account> */
    public array $created = [];

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badClaims(): iterable
    {
        yield 'another issuer' => [['iss' => 'https://evil.example'], 'iss'];
        yield 'another client' => [['aud' => 'someone-else'], 'aud'];
        yield 'expired' => [['exp' => 999], 'exp'];
        yield 'no subject' => [['sub' => ''], 'sub'];
    }

    #[Test]
    public function aGuestWhoseGoogleAccountIsFreeIsKeptWithIt(): void
    {
        $guest = $this->guest('Ada');

        [$outcome, $account, $newSession] = $this->signIn()->signIn($guest, $this->google('s-1', 'ada@gmail.com'));

        self::assertSame(GoogleSignIn::BOUND, $outcome);
        self::assertSame($guest, $account);
        self::assertFalse($newSession, 'The device keeps its cookie');
        self::assertSame('s-1', $guest->getGoogleSub());
        self::assertSame('ada@gmail.com', $guest->getGoogleEmail());
    }

    #[Test]
    public function signingInAgainOnTheSameAccountChangesNothing(): void
    {
        $guest = $this->guest('Ada');
        $this->signIn()->signIn($guest, $this->google('s-1', 'ada@gmail.com'));

        [$outcome, $account, $newSession] = $this->signIn()->signIn($guest, $this->google('s-1', 'ada@gmail.com'));

        self::assertSame([GoogleSignIn::BOUND, $guest, false], [$outcome, $account, $newSession]);
        self::assertSame([], $this->created);
    }

    #[Test]
    public function aGuestSigningInWithAnotherAccountsGoogleSwitchesToThatAccount(): void
    {
        $kept = $this->guest('Ada');
        $this->signIn()->signIn($kept, $this->google('s-1', 'ada@gmail.com'));
        $other = $this->guest('Wanderer 12');

        [$outcome, $account, $newSession] = $this->signIn()->signIn($other, $this->google('s-1', 'ada@gmail.com'));

        self::assertSame(GoogleSignIn::SIGNED_IN, $outcome);
        self::assertSame($kept, $account);
        self::assertTrue($newSession);
        self::assertNull($other->getGoogleSub(), 'The guest stays a guest of its own');
    }

    #[Test]
    public function aNewDeviceSignsInToTheKeptAccount(): void
    {
        $kept = $this->guest('Ada');
        $this->signIn()->signIn($kept, $this->google('s-1', 'ada@gmail.com'));

        [$outcome, $account, $newSession] = $this->signIn()->signIn(null, $this->google('s-1', 'ada@gmail.com'));

        self::assertSame([GoogleSignIn::SIGNED_IN, $kept, true], [$outcome, $account, $newSession]);
    }

    #[Test]
    public function aNewDeviceWithAFreeGoogleAccountGetsANewAccountNamedAfterIt(): void
    {
        [$outcome, $account, $newSession] = $this->signIn()->signIn(null, $this->google('s-2', 'carol@example.com', 'Carol'));

        self::assertSame(GoogleSignIn::CREATED, $outcome);
        self::assertTrue($newSession);
        self::assertSame('Carol', $account->getName());
        self::assertSame('s-2', $account->getGoogleSub());
    }

    #[Test]
    public function aGoogleNameThatDoesNotFitGivesASuggestedOne(): void
    {
        [, $account] = $this->signIn()->signIn(null, $this->google('s-3', null, '<>'));

        self::assertMatchesRegularExpression('/^[A-Z][a-z]+ \d{2}$/', $account->getName());
    }

    #[Test]
    public function anAccountKeptWithOneGoogleAccountIsNotRebound(): void
    {
        $kept = $this->guest('Ada');
        $this->signIn()->signIn($kept, $this->google('s-1', 'ada@gmail.com'));

        [$outcome, $account] = $this->signIn()->signIn($kept, $this->google('s-9', 'other@gmail.com'));

        self::assertSame(GoogleSignIn::CREATED, $outcome, 'Another Google account gets an account of its own');
        self::assertNotSame($kept, $account);
        self::assertSame('s-1', $kept->getGoogleSub());
    }

    /**
     * @param array<string, mixed> $change
     */
    #[Test]
    #[DataProvider('badClaims')]
    public function anIdTokenWithBadClaimsIsRefused(array $change, string $reason): void
    {
        $claims = array_merge(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT, 'exp' => 2000, 'sub' => 's-1', 'email' => 'a@b.c', 'email_verified' => true], $change);

        try {
            GoogleIdentity::fromClaims($claims, self::CLIENT, 1000);
            self::fail('The claims should be refused: ' . $reason);
        } catch (GoogleSignInFailed $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    #[Test]
    public function anUnverifiedAddressIsNotKept(): void
    {
        $identity = GoogleIdentity::fromClaims(['iss' => 'accounts.google.com', 'aud' => [self::CLIENT], 'exp' => 2000, 'sub' => 's-1', 'email' => 'a@b.c', 'email_verified' => false], self::CLIENT, 1000);

        self::assertNull($identity->email);
        self::assertSame('s-1', $identity->sub);
    }

    #[Test]
    public function theFakeProviderSignsInAsAsked(): void
    {
        $fake = new FakeGoogleClient();
        $url = $fake->authorizeUrl('st', 'ch', 'http://x/api/account/google/callback', 'sub-7:dee@example.com');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $identity = $fake->exchange((string) $query['code'], 'v', 'http://x');

        self::assertSame('st', $query['state']);
        self::assertSame('sub-7', $identity->sub);
        self::assertSame('dee@example.com', $identity->email);
    }

    #[Test]
    public function theWayBackStaysOnThisSite(): void
    {
        self::assertSame('/table/abc', OAuthCookie::safeReturn('/table/abc'));
        self::assertSame('/?tab=heroes', OAuthCookie::safeReturn('/?tab=heroes'));
        foreach (['//evil.com', 'https://evil.com', '/\\evil.com', 'javascript:alert(1)', ''] as $bad) {
            self::assertSame('/', OAuthCookie::safeReturn($bad), $bad);
        }
    }

    private function guest(string $name): Account
    {
        return Account::createGuest($name, hash('sha256', $name . random_int(0, PHP_INT_MAX)), new \DateTimeImmutable());
    }

    private function google(string $sub, ?string $email, ?string $given = null): GoogleIdentity
    {
        return new GoogleIdentity($sub, $email, $email !== null, $given);
    }

    private function signIn(): GoogleSignIn
    {
        $test = $this;
        $store = new class ($test) implements GoogleAccounts {
            public function __construct(private readonly GoogleSignInTest $test) {}

            public function findByGoogleSub(string $sub): ?Account
            {
                return $this->test->bySub[$sub] ?? null;
            }

            public function bindGoogle(Account $account, string $sub, ?string $email): void
            {
                $account->bindGoogle($sub, $email);
                $this->test->bySub[$sub] = $account;
            }

            public function createForGoogle(string $name, string $sub, ?string $email): Account
            {
                $account = Account::createGuest($name, hash('sha256', $sub), new \DateTimeImmutable());
                $account->bindGoogle($sub, $email);
                $this->test->bySub[$sub] = $account;
                $this->test->created[] = $account;

                return $account;
            }
        };

        return new GoogleSignIn($store, new NullLogger());
    }
}
