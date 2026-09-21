<?php

declare(strict_types=1);

namespace App\Module\Identity\Repository;

use App\Module\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<User> */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function get(string $id): ?User
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * The only way to find a user by address.
     *
     * The email column is ciphertext and differs on every write, so it cannot be
     * queried. The caller hashes the address with Encryptor::hashForLookup() and
     * passes that.
     */
    public function byEmailHash(string $emailHash): ?User
    {
        return $this->findOneBy(['emailHash' => $emailHash]);
    }

    /** @return list<User> */
    public function membersOf(string $tenantId): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.memberships', 'm')
            ->where('m.tenantId = :tenant')
            ->andWhere('m.status = :active')
            ->setParameter('tenant', Uuid::fromString($tenantId), 'uuid')
            ->setParameter('active', 'active')
            ->getQuery()
            ->getResult();
    }
}
