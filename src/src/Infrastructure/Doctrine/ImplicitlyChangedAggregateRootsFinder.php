<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;

final class ImplicitlyChangedAggregateRootsFinder
{
    /**
     * @var array<int, bool>
     */
    private array $changedObjects = [];

    private function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return list<AggregateRoot>
     */
    public static function find(EntityManagerInterface $entityManager): array
    {
        $uow = $entityManager->getUnitOfWork();
        $collector = new self($entityManager);
        $aggregateRoots = [];

        foreach ($uow->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if (!$entity instanceof AggregateRoot) {
                    continue;
                }

                if ($uow->isEntityScheduled($entity)) {
                    continue;
                }

                if ($collector->cachedEntityOrCollectionChanged($entity)) {
                    $aggregateRoots[] = $entity;
                }
            }
        }

        return $aggregateRoots;
    }

    private function cachedEntityOrCollectionChanged(object $entityOrCollection): bool
    {
        $id = spl_object_id($entityOrCollection);

        if (isset($this->changedObjects[$id])) {
            return $this->changedObjects[$id];
        }

        $this->changedObjects[$id] = false;

        return $this->changedObjects[$id] = $this->entityOrCollectionChanged($entityOrCollection);
    }

    private function entityOrCollectionChanged(object $entityOrCollection): bool
    {
        $id = spl_object_id($entityOrCollection);
        $uow = $this->entityManager->getUnitOfWork();

        if ($entityOrCollection instanceof Collection) {
            if (isset($uow->getScheduledCollectionDeletions()[$id])) {
                return true;
            }

            if (isset($uow->getScheduledCollectionUpdates()[$id])) {
                return true;
            }

            /** @var Collection<array-key, object> $entityOrCollection */
            if ($entityOrCollection instanceof AbstractLazyCollection && !$entityOrCollection->isInitialized()) {
                return false;
            }

            foreach ($entityOrCollection as $entity) {
                if ($this->cachedEntityOrCollectionChanged($entity)) {
                    return true;
                }
            }

            return false;
        }

        if ($uow->isEntityScheduled($entityOrCollection)) {
            return true;
        }

        $metadata = $this->entityManager->getClassMetadata($entityOrCollection::class);

        foreach ($metadata->getAssociationNames() as $associationName) {
            $associationValue = $metadata->getFieldValue($entityOrCollection, $associationName);

            if (\is_object($associationValue) && $this->cachedEntityOrCollectionChanged($associationValue)) {
                return true;
            }
        }

        return false;
    }
}
