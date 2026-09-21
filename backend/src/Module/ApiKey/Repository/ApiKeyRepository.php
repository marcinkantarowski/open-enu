<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Repository;

use App\Module\ApiKey\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use OpenEnu\Kernel\Attribute\Unscoped;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ApiKey> */
final class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly Connection $db,
        private readonly ScopeContext $scope,
    ) {
        parent::__construct($registry, ApiKey::class);
    }

    public function get(string $id): ?ApiKey
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * Resolve a presented key to its record.
     *
     * Deliberately unscoped, and this is the one place it is correct to be:
     * authentication happens BEFORE the tenant is known - the key is what
     * establishes it. A scoped query here would need the answer it is computing.
     *
     * Both halves must escape the filter, and the second half is the one that
     * is easy to miss: raw SQL finds the id, but `find()` goes through the ORM
     * and is scoped, so with no tenant established the fail-closed filter
     * returns nothing and every key appears invalid. The filter was right - the
     * fix is to say explicitly that this lookup precedes scope.
     */
    #[Unscoped(reason: 'authentication precedes tenant scope; the key IS what establishes it')]
    public function authenticate(string $keyHash): ?ApiKey
    {
        $id = $this->db->fetchOne('SELECT id FROM api_key WHERE key_hash = ?', [$keyHash]);

        if (!\is_string($id)) {
            return null;
        }

        return $this->scope->runUnscoped(
            'resolving an API key to the tenant it belongs to',
            fn (): ?ApiKey => $this->find(Uuid::fromString($id)),
        );
    }

    /** @return list<ApiKey> */
    public function forCurrentTenant(): array
    {
        return $this->createQueryBuilder('k')
            ->orderBy('k.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
