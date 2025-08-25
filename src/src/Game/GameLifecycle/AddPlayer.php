<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

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
    ) {}
}
