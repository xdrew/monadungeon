<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Game\Player\Player;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Event;

/**
 * @psalm-immutable
 */
final readonly class PlayerAdded implements Event
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public int $hp = Player::MAX_HP,
        public ?string $externalId = null,
        public ?string $username = null,
        public ?string $walletAddress = null,
    ) {}
}
