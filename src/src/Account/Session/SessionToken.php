<?php

declare(strict_types=1);

namespace App\Account\Session;

/**
 * The secret in the session cookie: 32 random bytes, base64url. Only its sha256 is stored.
 */
final class SessionToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function looksValid(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }
}
