<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * The host starts the table: at least two seats taken, every human seat holds a hero (unless heroes are random).
 *
 * @psalm-immutable
 *
 * @implements Message<void>
 */
final readonly class StartTable implements Message
{
    public function __construct(
        public Uuid $gameId,
        public string $accountId,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
    ) {}
}
