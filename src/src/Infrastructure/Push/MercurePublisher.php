<?php

declare(strict_types=1);

namespace App\Infrastructure\Push;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Psr\Log\LoggerInterface;

/**
 * Publishes to the Mercure hub: a form POST with a short-lived HS256 publisher JWT, 2 seconds at most.
 */
final class MercurePublisher implements Publisher
{
    private const int TIMEOUT_MS = 2000;

    private ?string $token = null;

    private int $tokenExpires = 0;

    public function __construct(
        private readonly string $hubUrl,
        private readonly string $jwtSecret,
        private readonly LoggerInterface $logger,
    ) {}

    public function publish(string $topic, array $data): void
    {
        try {
            $body = http_build_query(['topic' => $topic, 'data' => json_encode($data, JSON_THROW_ON_ERROR)]);
            $curl = curl_init($this->hubUrl);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
                CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
            ]);
            $answer = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($answer === false || $status !== 200) {
                $this->logger->warning('push.failed', ['topic' => $topic, 'type' => $data['type'] ?? null, 'status' => $status, 'error' => $error]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('push.failed', ['topic' => $topic, 'type' => $data['type'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    private function token(): string
    {
        $now = time();
        if ($this->token === null || $this->tokenExpires - 60 < $now) {
            $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($this->jwtSecret));
            $expires = new \DateTimeImmutable('@' . ($now + 3600));
            $this->token = $config->builder()
                ->withClaim('mercure', ['publish' => ['*']])
                ->expiresAt($expires)
                ->getToken($config->signer(), $config->signingKey())
                ->toString();
            $this->tokenExpires = $now + 3600;
        }

        return $this->token;
    }
}
