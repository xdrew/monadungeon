<?php

declare(strict_types=1);

namespace App\Account\Google;

/**
 * Dev/test only (GOOGLE_CLIENT_ID=fake): no Google at all. The start goes straight back to the callback with a
 * code that carries the identity asked for ("sub:email"), so the e2e can sign in as anyone.
 */
final readonly class FakeGoogleClient implements GoogleOAuthClient
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function authorizeUrl(string $state, string $codeChallenge, string $redirectUri, ?string $hint = null): string
    {
        $code = 'fake.' . rtrim(strtr(base64_encode($hint ?? 'fake-sub:fake@example.com'), '+/', '-_'), '=');

        return $redirectUri . '?' . http_build_query(['code' => $code, 'state' => $state]);
    }

    public function exchange(string $code, string $codeVerifier, string $redirectUri): GoogleIdentity
    {
        if (!str_starts_with($code, 'fake.')) {
            throw new GoogleSignInFailed('fake_code');
        }
        $hint = (string) base64_decode(strtr(substr($code, 5), '-_', '+/'), true);
        [$sub, $email] = array_pad(explode(':', $hint, 2), 2, null);
        if ($sub === '') {
            throw new GoogleSignInFailed('fake_code');
        }

        return new GoogleIdentity($sub, $email ?: null, true, $email ? ucfirst(explode('@', $email)[0]) : null);
    }
}
