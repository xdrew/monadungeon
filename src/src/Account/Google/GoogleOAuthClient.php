<?php

declare(strict_types=1);

namespace App\Account\Google;

interface GoogleOAuthClient
{
    public function isAvailable(): bool;

    /**
     * Where the browser goes to sign in. $hint is used by the dev/test fake only ("sub:email").
     */
    public function authorizeUrl(string $state, string $codeChallenge, string $redirectUri, ?string $hint = null): string;

    /**
     * @throws GoogleSignInFailed
     */
    public function exchange(string $code, string $codeVerifier, string $redirectUri): GoogleIdentity;
}
