<?php

declare(strict_types=1);

namespace App\Account;

use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * Who plays: a guest account behind a session cookie, known by a name. A seat at a table (Player.externalId)
 * and the Hall of fame point at it.
 */
#[Entity]
#[Table(schema: 'account', name: 'account')]
#[UniqueConstraint(name: 'unique_account_token_hash', columns: ['token_hash'])]
#[UniqueConstraint(name: 'unique_account_google_sub', columns: ['google_sub'])]
class Account
{
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    /**
     * The Google account this one is kept with ("sub" of the ID token), so it can be signed in elsewhere.
     */
    #[Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $googleSub = null;

    /**
     * The Google address, shown as "kept with a…@gmail.com".
     */
    #[Column(type: Types::STRING, length: 320, nullable: true)]
    private ?string $googleEmail = null;

    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $id,
        #[Column(type: Types::STRING, length: 24)]
        private string $name,
        #[Column(type: Types::STRING, length: 64)]
        private readonly string $tokenHash,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->lastSeenAt = $createdAt;
    }

    public static function createGuest(string $name, string $tokenHash, \DateTimeImmutable $at): self
    {
        return new self(Uuid::v7(), AccountName::normalize($name), $tokenHash, $at);
    }

    public function bindGoogle(string $sub, ?string $email): void
    {
        if ($this->googleSub !== null && $this->googleSub !== $sub) {
            throw new \DomainException('This account is kept with another Google account');
        }
        $this->googleSub = $sub;
        $this->googleEmail = $email;
    }

    public function getGoogleSub(): ?string
    {
        return $this->googleSub;
    }

    public function getGoogleEmail(): ?string
    {
        return $this->googleEmail;
    }

    public function rename(string $name): void
    {
        $this->name = AccountName::normalize($name);
    }

    /**
     * @return bool whether the time was written (at most once a minute)
     */
    public function touch(\DateTimeImmutable $at): bool
    {
        if ($at->getTimestamp() - $this->lastSeenAt->getTimestamp() < 60) {
            return false;
        }
        $this->lastSeenAt = $at;

        return true;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
