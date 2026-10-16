<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * The host seats the AI on one free seat of the table.
 *
 * @psalm-immutable
 *
 * @implements Message<void>
 */
final readonly class AddAiToTable implements Message
{
    public function __construct(
        public Uuid $gameId,
        public string $accountId,
        public Uuid $playerId,
    ) {}
}
