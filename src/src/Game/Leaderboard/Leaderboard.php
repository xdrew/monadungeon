<?php

declare(strict_types=1);

namespace App\Game\Leaderboard;

use App\Infrastructure\Uuid\DoctrineDBAL\UuidType;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[Entity]
#[Table(schema: 'game', name: 'leaderboard')]
#[UniqueConstraint(name: 'unique_player_identifier', columns: ['username', 'external_id'])]
#[Index(name: 'idx_leaderboard_username', columns: ['username'])]
#[Index(name: 'idx_leaderboard_external_id', columns: ['external_id'])]
#[Index(name: 'idx_leaderboard_victories', columns: ['victories'])]
#[Index(name: 'idx_leaderboard_total_games', columns: ['total_games'])]
class Leaderboard
{
    #[Id]
    #[Column(type: UuidType::class)]
    private readonly Uuid $id;

    #[Column(type: Types::STRING)]
    private string $username;

    #[Column(type: Types::STRING, nullable: true)]
    private ?string $walletAddress;

    #[Column(type: Types::STRING, nullable: true)]
    private ?string $externalId;

    #[Column(type: Types::INTEGER)]
    private int $victories = 0;

    #[Column(type: Types::INTEGER)]
    private int $totalGames = 0;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $username,
        ?string $walletAddress = null,
        ?string $externalId = null,
    ) {
        $this->id = Uuid::v7();
        $this->username = $username;
        $this->walletAddress = $walletAddress;
        $this->externalId = $externalId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getWalletAddress(): ?string
    {
        return $this->walletAddress;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getVictories(): int
    {
        return $this->victories;
    }

    public function getTotalGames(): int
    {
        return $this->totalGames;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function incrementVictories(): void
    {
        $this->victories++;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function incrementTotalGames(): void
    {
        $this->totalGames++;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateStats(bool $isWinner): void
    {
        $this->totalGames++;
        if ($isWinner) {
            $this->victories++;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateUsername(string $username): void
    {
        $this->username = $username;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateExternalId(?string $externalId): void
    {
        $this->externalId = $externalId;
        $this->updatedAt = new \DateTimeImmutable();
    }
}