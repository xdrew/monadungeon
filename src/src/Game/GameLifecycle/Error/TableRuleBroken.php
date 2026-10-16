<?php

declare(strict_types=1);

namespace App\Game\GameLifecycle\Error;

/**
 * A table action that the rules refuse; the code is a stable key for the API and the client.
 */
final class TableRuleBroken extends \DomainException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
