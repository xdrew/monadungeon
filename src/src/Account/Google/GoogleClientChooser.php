<?php

declare(strict_types=1);

namespace App\Account\Google;

/**
 * The client in use: the fake one for GOOGLE_CLIENT_ID=fake in dev/test, Google's otherwise.
 */
final readonly class GoogleClientChooser
{
    public function __construct(
        private string $clientId,
        private string $appEnv,
        private GoogleHttpClient $google,
        private FakeGoogleClient $fake,
    ) {}

    public function client(): GoogleOAuthClient
    {
        return $this->clientId === 'fake' && \in_array($this->appEnv, ['dev', 'test'], true) ? $this->fake : $this->google;
    }

    public function isFake(): bool
    {
        return $this->client() === $this->fake;
    }
}
