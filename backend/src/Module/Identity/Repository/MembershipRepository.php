<?php

declare(strict_types=1);

namespace App\Module\Identity\Repository;

use App\Module\Identity\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Membership> */
final class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /** @return list<Membership> */
    public function forTenant(string $tenantId): array
    {
        return $this->findBy(['tenantId' => Uuid::fromString($tenantId)]);
    }

    public function countForTenant(string $tenantId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.tenantId = :tenant')
            ->setParameter('tenant', Uuid::fromString($tenantId), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
