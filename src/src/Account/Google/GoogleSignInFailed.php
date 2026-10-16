<?php

declare(strict_types=1);

namespace App\Account\Google;

/**
 * The sign-in did not go through; the reason is a short word for the logs (never a token or a code).
 */
final class GoogleSignInFailed extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Google sign-in failed: ' . $reason);
    }
}
