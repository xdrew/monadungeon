<?php

declare(strict_types=1);

namespace App\Account\Session;

use Symfony\Component\HttpFoundation\Cookie;

final class SessionCookie
{
    public const string NAME = 'karak_session';
    private const int DAYS = 400;

    public static function create(string $token, bool $secure): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue($token)
            ->withExpires(new \DateTimeImmutable(sprintf('+%d days', self::DAYS)))
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
