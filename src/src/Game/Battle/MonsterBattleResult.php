<?php

declare(strict_types=1);

namespace App\Game\Battle;

// BattleResult is now in the same namespace
use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class MonsterBattleResult implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public BattleResult $result,
        public FieldPlace $position,
        public FieldPlace $previousPosition,
        public int $diceRoll,
        public int $monsterHp,
        public \DateTimeImmutable $battledAt,
    ) {}
}
