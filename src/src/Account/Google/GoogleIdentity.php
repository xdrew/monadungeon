<?php

declare(strict_types=1);

namespace App\Account\Google;

/**
 * Who Google says signed in: the stable account id ("sub") and what is shown of it.
 */
final readonly class GoogleIdentity
{
    public function __construct(
        public string $sub,
        public ?string $email,
        public bool $emailVerified,
        public ?string $givenName,
    ) {}

    /**
     * The claims of an ID token received straight from Google's token endpoint (OIDC core 3.1.3.7: over TLS
     * from the issuer, so the claims are checked and the signature is not).
     *
     * @param array<string, mixed> $claims
     *
     * @throws GoogleSignInFailed
     */
    public static function fromClaims(array $claims, string $clientId, int $now): self
    {
        $iss = $claims['iss'] ?? null;
        if (!\in_array($iss, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new GoogleSignInFailed('iss');
        }
        $aud = $claims['aud'] ?? null;
        if (!(\is_string($aud) ? $aud === $clientId : \is_array($aud) && \in_array($clientId, $aud, true))) {
            throw new GoogleSignInFailed('aud');
        }
        if (!\is_int($claims['exp'] ?? null) || $claims['exp'] < $now) {
            throw new GoogleSignInFailed('exp');
        }
        $sub = $claims['sub'] ?? null;
        if (!\is_string($sub) || $sub === '') {
            throw new GoogleSignInFailed('sub');
        }
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? null) === 'true';
        $email = \is_string($claims['email'] ?? null) && $verified ? $claims['email'] : null;

        return new self($sub, $email, $verified, \is_string($claims['given_name'] ?? null) ? $claims['given_name'] : null);
    }

    /**
     * The payload of a JWT, unverified (see fromClaims).
     *
     * @return array<string, mixed>
     *
     * @throws GoogleSignInFailed
     */
    public static function jwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (\count($parts) !== 3) {
            throw new GoogleSignInFailed('id_token');
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $json !== false ? json_decode($json, true) : null;
        if (!\is_array($claims)) {
            throw new GoogleSignInFailed('id_token');
        }

        return $claims;
    }
}
