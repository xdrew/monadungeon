<?php

declare(strict_types=1);

namespace App\Account;

use App\Account\Google\GoogleAccounts;
use App\Account\Session\SessionToken;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AccountService implements GoogleAccounts
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

    /**
     * The account behind a cookie: a device session first, then the guest's own first-device hash.
     */
    public function findByToken(string $token): ?Account
    {
        if (!SessionToken::looksValid($token)) {
            return null;
        }
        $hash = SessionToken::hash($token);
        $session = $this->entityManager->getRepository(AccountSession::class)->findOneBy(['tokenHash' => $hash]);
        if ($session !== null) {
            return $this->find($session->getAccountId());
        }

        return $this->entityManager->getRepository(Account::class)->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * Signs this device in to an account: a new session, its token for the cookie (never stored).
     */
    public function openSession(Account $account): string
    {
        $token = SessionToken::generate();
        $this->entityManager->persist(AccountSession::open($account->getId(), SessionToken::hash($token), new \DateTimeImmutable()));
        $this->entityManager->flush();

        return $token;
    }

    /**
     * Signs this device out: its session is gone (a guest's first-device hash is never dropped this way).
     */
    public function closeSession(string $token): bool
    {
        if (!SessionToken::looksValid($token)) {
            return false;
        }
        $session = $this->entityManager->getRepository(AccountSession::class)->findOneBy(['tokenHash' => SessionToken::hash($token)]);
        if ($session === null) {
            return false;
        }
        $this->entityManager->remove($session);
        $this->entityManager->flush();

        return true;
    }

    public function findByGoogleSub(string $sub): ?Account
    {
        return $this->entityManager->getRepository(Account::class)->findOneBy(['googleSub' => $sub]);
    }

    /**
     * A new account made by signing in with Google on a device without one; its first-device hash belongs
     * to nobody (the device gets a session).
     */
    public function createForGoogle(string $name, string $sub, ?string $email): Account
    {
        $account = Account::createGuest($name, SessionToken::hash(SessionToken::generate()), new \DateTimeImmutable());
        $account->bindGoogle($sub, $email);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    public function bindGoogle(Account $account, string $sub, ?string $email): void
    {
        $account->bindGoogle($sub, $email);
        $this->entityManager->flush();
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
