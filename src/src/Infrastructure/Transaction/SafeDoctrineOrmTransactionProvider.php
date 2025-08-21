<?php

declare(strict_types=1);

namespace App\Infrastructure\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Telephantast\MessageBus\Transaction\TransactionProvider;

/**
 * Custom transaction provider that doesn't close EntityManager on errors
 * Only performs rollback while keeping the EntityManager open for subsequent operations
 */
final class SafeDoctrineOrmTransactionProvider implements TransactionProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function wrapInTransaction(callable $operation): mixed
    {
        // Start a new transaction if not already in one
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->beginTransaction();
        }

        try {
            // Execute the operation
            $result = $operation();
            
            // Flush and commit if we're still in a transaction
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->flush();
                $this->entityManager->commit();
            }
            
            return $result;
        } catch (\Throwable $e) {
            // Rollback but DON'T close the EntityManager (unlike the default provider)
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            
            // Clear the EntityManager to ensure clean state without closing it
            // This allows subsequent operations to continue
            $this->entityManager->clear();
            
            // Re-throw the exception for proper error handling
            throw $e;
        }
    }
}