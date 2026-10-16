<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * A player leaves a table before it starts; the host leaving closes the table.
 *
 * @psalm-immutable
 *
 * @implements Message<void>
 */
final readonly class LeaveTable implements Message
{
    public function __construct(
        public Uuid $gameId,
        public string $accountId,
    ) {}
}
