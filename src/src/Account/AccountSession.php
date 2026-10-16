<?php

declare(strict_types=1);

namespace App\Account;

use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * One device signed in to an account (an account kept with Google can have several). Only the token's
 * sha256 is stored; the guest's first device keeps the hash on the account itself (part 1).
 */
#[Entity]
#[Table(schema: 'account', name: 'session')]
#[UniqueConstraint(name: 'unique_session_token_hash', columns: ['token_hash'])]
#[Index(name: 'idx_session_account', columns: ['account_id'])]
class AccountSession
{
    private function __construct(
        #[Id]
        #[Column(type: UuidType::class)]
        private readonly Uuid $id,
        #[Column(type: UuidType::class)]
        private readonly Uuid $accountId,
        #[Column(type: Types::STRING, length: 64)]
        private readonly string $tokenHash,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function open(Uuid $accountId, string $tokenHash, \DateTimeImmutable $at): self
    {
        return new self(Uuid::v7(), $accountId, $tokenHash, $at);
    }

    public function getAccountId(): Uuid
    {
        return $this->accountId;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }
}
