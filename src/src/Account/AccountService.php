<?php

declare(strict_types=1);

namespace App\Account;

use App\Account\Session\SessionToken;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AccountService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{Account, string} the account and its session token (to put into the cookie, never stored)
     *
     * @throws \InvalidArgumentException for a name that does not fit
     */
    public function createGuest(string $name): array
    {
        $token = SessionToken::generate();
        $account = Account::createGuest($name, SessionToken::hash($token), new \DateTimeImmutable());
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $this->logger->info('account.created', ['accountId' => $account->getId()->toString(), 'nameLength' => mb_strlen($account->getName())]);

        return [$account, $token];
    }

    /**
     * @throws \InvalidArgumentException for a name that does not fit
     */
    public function rename(Account $account, string $name): void
    {
        $account->rename($name);
        $this->entityManager->flush();
        $this->logger->info('account.renamed', ['accountId' => $account->getId()->toString(), 'nameLength' => mb_strlen($account->getName())]);
    }

    public function findByToken(string $token): ?Account
    {
        if (!SessionToken::looksValid($token)) {
            return null;
        }

        return $this->entityManager->getRepository(Account::class)->findOneBy(['tokenHash' => SessionToken::hash($token)]);
    }

    public function find(Uuid $id): ?Account
    {
        return $this->entityManager->getRepository(Account::class)->find($id);
    }

    public function touch(Account $account): void
    {
        if ($account->touch(new \DateTimeImmutable())) {
            $this->entityManager->flush();
        }
    }
}
