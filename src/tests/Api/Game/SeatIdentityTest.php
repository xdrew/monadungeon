<?php

declare(strict_types=1);

namespace App\Tests\Api\Game;

use App\Api\Game\AddPlayer\SeatIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Hot-seat: the first human seat is the account's, the later ones are named guests; other games keep the account.
 */
#[CoversClass(SeatIdentity::class)]
final class SeatIdentityTest extends TestCase
{
    private const string ACCOUNT = '01a14f00-0000-7000-8000-000000000001';

    #[Test]
    public function theFirstHumanSeatOfAHotseatGameIsTheAccount(): void
    {
        $seat = SeatIdentity::for(true, self::ACCOUNT, 'Seeker 29', [['externalId' => null, 'isAi' => true]], 'Someone');

        self::assertSame(self::ACCOUNT, $seat->externalId);
        self::assertSame('Seeker 29', $seat->username, 'The account seat keeps the account name');
        self::assertFalse($seat->guest);
    }

    #[Test]
    public function laterHumanSeatsAreNamedGuests(): void
    {
        $seats = [['externalId' => self::ACCOUNT, 'isAi' => false], ['externalId' => null, 'isAi' => true]];

        $named = SeatIdentity::for(true, self::ACCOUNT, 'Seeker 29', $seats, '  Mira  ');
        self::assertNull($named->externalId, 'A guest has no account: off the players\' board');
        self::assertSame('Mira', $named->username);
        self::assertTrue($named->guest);

        $blank = SeatIdentity::for(true, self::ACCOUNT, 'Seeker 29', $seats, ' ');
        self::assertSame('Adventurer 2', $blank->username, 'Numbered by the human seats, the AI not counted');
    }

    #[Test]
    public function aGameInOneBrowserAlwaysSeatsTheAccount(): void
    {
        $seat = SeatIdentity::for(false, self::ACCOUNT, 'Seeker 29', [['externalId' => self::ACCOUNT, 'isAi' => false]], 'Mira');

        self::assertSame(self::ACCOUNT, $seat->externalId);
        self::assertSame('Seeker 29', $seat->username);
    }
}
