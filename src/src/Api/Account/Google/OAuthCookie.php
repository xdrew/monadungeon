<?php

declare(strict_types=1);

namespace App\Api\Account\Google;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the callback needs to trust the answer: the state, the PKCE verifier and where to go back,
 * in a short-lived HttpOnly cookie that only the Google routes see.
 */
final class OAuthCookie
{
    public const string NAME = 'karak_oauth';
    private const string PATH = '/api/account/google';
    private const int MINUTES = 10;

    /**
     * @return array{state: string, verifier: string, return: string}
     */
    public static function begin(string $returnPath): array
    {
        return ['state' => self::random(), 'verifier' => self::random() . self::random(), 'return' => self::safeReturn($returnPath)];
    }

    /**
     * @param array{state: string, verifier: string, return: string} $flow
     */
    public static function create(array $flow, bool $secure): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue(rtrim(strtr(base64_encode(json_encode($flow, JSON_THROW_ON_ERROR)), '+/', '-_'), '='))
            ->withExpires(new \DateTimeImmutable(sprintf('+%d minutes', self::MINUTES)))
            ->withPath(self::PATH)
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    public static function clear(bool $secure): Cookie
    {
        return Cookie::create(self::NAME)->withValue('')->withExpires(1)->withPath(self::PATH)->withSecure($secure)->withHttpOnly(true);
    }

    /**
     * @return array{state: string, verifier: string, return: string}|null
     */
    public static function read(Request $request): ?array
    {
        $raw = (string) $request->cookies->get(self::NAME, '');
        $json = $raw !== '' ? base64_decode(strtr($raw, '-_', '+/'), true) : false;
        $flow = $json !== false ? json_decode($json, true) : null;
        if (!\is_array($flow) || !\is_string($flow['state'] ?? null) || !\is_string($flow['verifier'] ?? null) || !\is_string($flow['return'] ?? null)) {
            return null;
        }

        return ['state' => $flow['state'], 'verifier' => $flow['verifier'], 'return' => self::safeReturn($flow['return'])];
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Only a path on this site ("/table/…"), never another host ("//evil", "https://…").
     */
    public static function safeReturn(string $path): string
    {
        return preg_match('#^/(?![/\\\\])[^\s]*$#', $path) === 1 ? $path : '/';
    }

    /**
     * The callback as the browser reaches it (nginx passes Host, X-Forwarded-Proto and X-Forwarded-Port).
     */
    public static function redirectUri(Request $request): string
    {
        $scheme = (string) ($request->headers->get('X-Forwarded-Proto') ?: $request->getScheme());
        $host = (string) preg_replace('/:\d+$/', '', (string) $request->headers->get('Host', $request->getHost()));
        $port = (string) ($request->headers->get('X-Forwarded-Port') ?: $request->getPort());
        $default = ($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80') || $port === '';

        return sprintf('%s://%s%s%s', $scheme, $host, $default ? '' : ':' . $port, self::PATH . '/callback');
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
