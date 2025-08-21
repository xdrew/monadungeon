<?php

declare(strict_types=1);

namespace App\Game\Turn;

use App\Infrastructure\Time\WallClock;
use App\Infrastructure\Uuid\Uuid;
use Psr\Clock\ClockInterface;
use Telephantast\Message\Message;

/**
 * @implements Message<void>
 */
final class PerformTurnAction implements Message
{
    private static ?ClockInterface $clock = null;

    public \DateTimeImmutable $at;

    public function __construct(
        public Uuid $turnId,
        public Uuid $gameId,
        public Uuid $playerId,
        public TurnAction $action,
        public ?Uuid $tileId = null,
        public ?array $additionalData = null,
        ?\DateTimeImmutable $at = null,
    ) {
        $this->at = $at ?? self::getClock()->now();
    }

    public static function setClock(ClockInterface $clock): void
    {
        self::$clock = $clock;
    }

    private static function getClock(): ClockInterface
    {
        return self::$clock ?? new WallClock();
    }
}
