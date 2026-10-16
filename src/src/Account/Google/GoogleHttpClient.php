<?php

declare(strict_types=1);

namespace App\Account\Google;

/**
 * Google's OAuth 2.0 endpoints over curl: the authorization code flow with PKCE.
 */
final readonly class GoogleHttpClient implements GoogleOAuthClient
{
    private const string AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
    ) {}

    public function isAvailable(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function authorizeUrl(string $state, string $codeChallenge, string $redirectUri, ?string $hint = null): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
    }

    public function exchange(string $code, string $codeVerifier, string $redirectUri): GoogleIdentity
    {
        $curl = curl_init(self::TOKEN);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
                'code_verifier' => $codeVerifier,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $answer = \is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !\is_array($answer) || !\is_string($answer['id_token'] ?? null)) {
            throw new GoogleSignInFailed('exchange_' . $status);
        }

        return GoogleIdentity::fromClaims(GoogleIdentity::jwtPayload($answer['id_token']), $this->clientId, time());
    }
}
