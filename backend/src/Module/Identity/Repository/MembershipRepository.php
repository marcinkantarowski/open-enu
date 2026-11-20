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

    /**
     * Active memberships only - the same people `UserRepository::membersOf()`
     * lists. Counting invitations and suspensions too made the operator's
     * tenant list say "2 members" above a tenant page that showed one.
     */
    public function countActiveForTenant(string $tenantId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.tenantId = :tenant')
            ->andWhere('m.status = :active')
            ->setParameter('tenant', Uuid::fromString($tenantId), 'uuid')
            ->setParameter('active', Membership::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countWithRole(string $tenantId, string $role): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.tenantId = :tenant AND m.role = :role')
            ->setParameter('tenant', Uuid::fromString($tenantId), 'uuid')
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
