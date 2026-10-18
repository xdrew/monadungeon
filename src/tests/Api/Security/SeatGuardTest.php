<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\Security\SeatGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeatGuard::class)]
final class SeatGuardTest extends TestCase
{
    private const string ME = 'acc-me';
    private const string RIVAL = 'acc-rival';

    /**
     * @return iterable<string, array{array{isAi: bool, externalId: ?string}, list<string>, ?string, bool, string}>
     */
    public static function cases(): iterable
    {
        $mine = ['isAi' => false, 'externalId' => self::ME];
        $ai = ['isAi' => true, 'externalId' => null];
        $unowned = ['isAi' => false, 'externalId' => null];

        yield 'my seat' => [$mine, [self::ME], self::ME, false, SeatGuard::ALLOWED];
        yield 'my seat, someone else' => [$mine, [self::ME, self::RIVAL], self::RIVAL, false, SeatGuard::NOT_YOURS];
        yield 'my seat, no session' => [$mine, [self::ME], null, false, SeatGuard::NOT_YOURS];
        yield 'an AI turn for a human seat' => [$mine, [self::ME], self::ME, true, SeatGuard::NOT_AI];
        yield 'the AI of my table' => [$ai, [self::ME], self::ME, true, SeatGuard::ALLOWED];
        yield 'the AI of a table I do not sit at' => [$ai, [self::ME], self::RIVAL, true, SeatGuard::NOT_YOURS];
        yield 'the AI of a table nobody owns' => [$ai, [], null, true, SeatGuard::ALLOWED];
        yield 'an unowned seat (old game, test mode)' => [$unowned, [], null, false, SeatGuard::ALLOWED];
        yield 'a hot-seat guest, from the owner\'s browser' => [$unowned, [self::ME], self::ME, false, SeatGuard::ALLOWED];
        yield 'a hot-seat guest, from someone else' => [$unowned, [self::ME], self::RIVAL, false, SeatGuard::NOT_YOURS];
        yield 'a hot-seat guest, no session' => [$unowned, [self::ME], null, false, SeatGuard::NOT_YOURS];
    }

    /**
     * @param array{isAi: bool, externalId: ?string} $seat
     * @param list<string> $owners
     */
    #[Test]
    #[DataProvider('cases')]
    public function itDecides(array $seat, array $owners, ?string $caller, bool $aiTurn, string $expected): void
    {
        self::assertSame($expected, SeatGuard::decide($seat, $owners, $caller, $aiTurn));
    }
}
