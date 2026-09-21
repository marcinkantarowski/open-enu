<?php

declare(strict_types=1);

namespace App\Module\Tenant\Repository;

use App\Module\Tenant\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Tenant> */
final class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function get(string $id): ?Tenant
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    public function bySlug(string $slug): ?Tenant
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Tenants whose first user never verified their address.
     *
     * @return list<Tenant>
     */
    public function pendingSince(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :pending')
            ->andWhere('t.createdAt < :before')
            ->setParameter('pending', Tenant::STATUS_PENDING)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Tenant> */
    public function page(int $offset, int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function total(): int
    {
        return (int) $this->createQueryBuilder('t')->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }
}
