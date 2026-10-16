<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\Account;
use App\Account\AccountName;
use App\Account\Session\SessionToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Account::class)]
#[CoversClass(AccountName::class)]
#[CoversClass(SessionToken::class)]
final class AccountTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function goodNames(): iterable
    {
        yield 'trimmed, spaces collapsed' => ['  Wanderer   42 ', 'Wanderer 42'];
        yield 'cyrillic' => ['Странник', 'Странник'];
        yield 'polish' => ['Łucja', 'Łucja'];
        yield 'punctuation' => ["O'Neil-Smith_2.0", "O'Neil-Smith_2.0"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'too short' => ['x'];
        yield 'too long' => [str_repeat('a', 25)];
        yield 'markup' => ['<b>me</b>'];
        yield 'emoji' => ['me 🐉'];
        yield 'blank' => ['   '];
    }

    #[Test]
    #[DataProvider('goodNames')]
    public function aNameIsNormalised(string $input, string $expected): void
    {
        self::assertSame($expected, AccountName::normalize($input));
    }

    #[Test]
    #[DataProvider('badNames')]
    public function aBadNameIsRefused(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AccountName::normalize($input);
    }

    #[Test]
    public function aGuestKeepsTheNameAndOnlyTheTokenHash(): void
    {
        $token = SessionToken::generate();
        $account = Account::createGuest(' Ada ', SessionToken::hash($token), new \DateTimeImmutable('2026-10-16 10:00:00'));

        self::assertSame('Ada', $account->getName());
        self::assertSame(hash('sha256', $token), $account->getTokenHash());
        self::assertNotSame($token, $account->getTokenHash());

        $account->rename('Horan fan');
        self::assertSame('Horan fan', $account->getName());
    }

    #[Test]
    public function aTokenIsUrlSafeAndUnique(): void
    {
        $a = SessionToken::generate();
        $b = SessionToken::generate();

        self::assertTrue(SessionToken::looksValid($a));
        self::assertNotSame($a, $b);
        self::assertFalse(SessionToken::looksValid('short'));
        self::assertFalse(SessionToken::looksValid(str_repeat('*', 43)));
    }

    #[Test]
    public function lastSeenIsWrittenAtMostOnceAMinute(): void
    {
        $account = Account::createGuest('Ada', SessionToken::hash(SessionToken::generate()), new \DateTimeImmutable('2026-10-16 10:00:00'));

        self::assertFalse($account->touch(new \DateTimeImmutable('2026-10-16 10:00:30')));
        self::assertTrue($account->touch(new \DateTimeImmutable('2026-10-16 10:01:01')));
        self::assertSame('2026-10-16 10:01:01', $account->getLastSeenAt()->format('Y-m-d H:i:s'));
    }
}
