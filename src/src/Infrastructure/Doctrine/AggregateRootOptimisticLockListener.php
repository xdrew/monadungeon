<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\OptimisticLockException;

#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::postRemove)]
final class AggregateRootOptimisticLockListener
{
    /**
     * @var \WeakMap<EntityManagerInterface, non-empty-list<AggregateRoot>>
     */
    private \WeakMap $aggregateRootsByEntityManager;

    public function __construct()
    {
        /** @var \WeakMap<EntityManagerInterface, non-empty-list<AggregateRoot>> */
        $this->aggregateRootsByEntityManager = new \WeakMap();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $aggregateRoots = ImplicitlyChangedAggregateRootsFinder::find($entityManager);

        if ($aggregateRoots !== []) {
            $this->aggregateRootsByEntityManager[$entityManager] = $aggregateRoots;
        }
    }

    /**
     * @throws OptimisticLockException
     */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->updateAggregateRootVersions($args->getObjectManager());
    }

    /**
     * @throws OptimisticLockException
     */
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->updateAggregateRootVersions($args->getObjectManager());
    }

    /**
     * @throws OptimisticLockException
     */
    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->updateAggregateRootVersions($args->getObjectManager());
    }

    /**
     * @throws OptimisticLockException
     */
    private function updateAggregateRootVersions(EntityManagerInterface $entityManager): void
    {
        if (!isset($this->aggregateRootsByEntityManager[$entityManager])) {
            return;
        }

        foreach ($this->aggregateRootsByEntityManager[$entityManager] as $aggregateRoot) {
            $this->updateVersion($entityManager, $aggregateRoot);
        }

        unset($this->aggregateRootsByEntityManager[$entityManager]);
    }

    /**
     * @throws OptimisticLockException
     */
    private function updateVersion(EntityManagerInterface $entityManager, AggregateRoot $aggregateRoot): void
    {
        $metadata = $entityManager->getClassMetadata($aggregateRoot::class);
        $version = (int) $metadata->getFieldValue($aggregateRoot, 'version');

        $queryBuilder = $entityManager
            ->createQueryBuilder()
            ->update($aggregateRoot::class, 'ar')
            ->set('ar.version', 'ar.version + 1')
            ->where('ar.version = :version')
            ->setParameter('version', $version);

        foreach ($metadata->getIdentifierValues($aggregateRoot) as $field => $value) {
            $queryBuilder
                ->andWhere("ar.{$field} = :{$field}")
                ->setParameter($field, $value, $metadata->getTypeOfField($field));
        }

        if ($queryBuilder->getQuery()->execute() !== 1) {
            throw OptimisticLockException::lockFailed($aggregateRoot);
        }

        $metadata->setFieldValue($aggregateRoot, 'version', $version + 1);
    }
}
