<?php

declare(strict_types=1);

namespace App\Tests\Api\Account;

use App\Api\Account\Google\OAuthCookie;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Google callback as the browser reaches it, behind the proxies the game runs behind.
 */
#[CoversClass(OAuthCookie::class)]
final class GoogleRedirectUriTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function cases(): iterable
    {
        // nginx in production terminates TLS and passes Host and the scheme; PHP itself is reached over http
        yield 'production nginx, no forwarded port' => [['Host' => 'karak.avelent.work', 'X-Forwarded-Proto' => 'https'], 'https://karak.avelent.work/api/account/google/callback'];
        yield 'production nginx, forwarded port' => [['Host' => 'karak.avelent.work', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'], 'https://karak.avelent.work/api/account/google/callback'];
        // the dev nginx on a port of its own, and the Vite proxy that rewrites Host
        yield 'dev nginx with a port' => [['Host' => '127.0.0.1:18080'], 'http://127.0.0.1:18080/api/account/google/callback'];
        yield 'Vite proxy' => [['Host' => 'php_monadungeon:8080', 'X-Forwarded-Host' => 'localhost:5173', 'X-Forwarded-Proto' => 'http'], 'http://localhost:5173/api/account/google/callback'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('cases')]
    public function theCallbackIsWhereTheBrowserIs(array $headers, string $expected): void
    {
        $server = ['SERVER_PORT' => '8080'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::create('/api/account/google/start', 'GET', server: $server);

        self::assertSame($expected, OAuthCookie::redirectUri($request));
    }
}
