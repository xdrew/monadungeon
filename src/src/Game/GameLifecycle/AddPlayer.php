<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Game\Player\HeroType;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 *
 * @implements Message<void>
 */
final readonly class AddPlayer implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public ?string $externalId = null,
        public ?string $username = null,
        public ?string $walletAddress = null,
        public bool $isAi = false,
        public ?HeroType $hero = null,
    ) {}
}
