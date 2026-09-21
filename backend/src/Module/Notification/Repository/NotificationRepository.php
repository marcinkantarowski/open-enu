<?php

declare(strict_types=1);

namespace App\Module\Notification\Repository;

use App\Module\Notification\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Notification> */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function get(string $id): ?Notification
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * One person's feed.
     *
     * The user is always a parameter and never the ambient session: an endpoint
     * that returned "the current user's" notifications by reading them from
     * somewhere else is one refactor away from returning somebody else's.
     *
     * @return list<Notification>
     */
    public function feedFor(string $userId, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.userId = :user')
            ->setParameter('user', Uuid::fromString($userId))
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function unreadCountFor(string $userId): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.userId = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', Uuid::fromString($userId))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
