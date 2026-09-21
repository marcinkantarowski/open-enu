<?php

declare(strict_types=1);

namespace App\Module\Audit\Repository;

use App\Module\Audit\Entity\AuditEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use OpenEnu\Kernel\Attribute\Unscoped;

/** @extends ServiceEntityRepository<AuditEntry> */
final class AuditEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly Connection $db)
    {
        parent::__construct($registry, AuditEntry::class);
    }

    /** @return list<AuditEntry> */
    public function recent(?string $tenantId, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.recordedAt', 'DESC')
            // Tie-break on the id, which is a UUIDv7 and therefore ordered by
            // creation. Two entries written in the same second otherwise come
            // back in whatever order Postgres feels like, and a trail whose
            // order changes between two reads of the same page is not a trail.
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(min($limit, 200));

        if ($tenantId !== null) {
            $qb->where('a.tenantId = :tenant')->setParameter('tenant', $tenantId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Everything one person did, for a subject-access request.
     *
     * Deliberately without before/after snapshots: those are arbitrary JSON and
     * can contain OTHER people's data, which a subject-access request is not a
     * route to.
     *
     * @return list<array<string, mixed>>
     */
    #[Unscoped(reason: 'audit rows are deliberately not tenant-filtered; a subject may have acted across tenants')]
    public function actionsBy(string $userId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT action, tenant_id, subject_id, succeeded, recorded_at
             FROM audit_entry
             WHERE actor_id = ? OR on_behalf_of_id = ?
             ORDER BY recorded_at DESC',
            [$userId, $userId],
        );
    }

    /**
     * Replace a person's identifier with a tombstone, keeping the events.
     *
     * Snapshots are dropped rather than scrubbed: proving a name is not inside
     * arbitrary JSON is not something a query can do.
     */
    #[Unscoped(reason: 'anonymising a subject across every tenant they acted in')]
    public function anonymise(string $userId, string $tombstone): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE audit_entry
             SET actor_id = :tombstone,
                 on_behalf_of_id = CASE WHEN on_behalf_of_id = :user THEN :tombstone ELSE on_behalf_of_id END,
                 before = NULL,
                 after = NULL
             WHERE actor_id = :user OR on_behalf_of_id = :user',
            ['user' => $userId, 'tombstone' => $tombstone],
        );
    }
}
