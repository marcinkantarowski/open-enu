<?php

declare(strict_types=1);

namespace App\Module\Example\Repository;

use App\Module\Example\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The only place a query for this module lives.
 *
 * Nothing here filters by tenant: `ScopeFilter` adds `tenant_id = ?` to every
 * one of these, and with no tenant in context they return nothing rather than
 * everything (ADR-0004). Writing the filter by hand here would be the bug -
 * it would look correct on the query somebody remembered.
 *
 * Not `final`, unlike most classes here, and for one concrete reason: PHPUnit
 * cannot double a final class, so a final repository is one no handler can be
 * unit-tested against. Repositories are the exception; keep everything else
 * final.
 *
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    public function get(string $id): ?Project
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /** @return list<Project> */
    public function page(int $offset, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function total(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Project> */
    public function active(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->setParameter('status', Project::STATUS_ACTIVE)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
