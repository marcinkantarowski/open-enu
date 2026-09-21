<?php

declare(strict_types=1);

namespace App\Module\Manager\Repository;

use App\Module\Manager\Entity\PlatformManager;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PlatformManager> */
final class PlatformManagerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformManager::class);
    }

    public function get(string $id): ?PlatformManager
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /** The email column is ciphertext, so lookups go through the deterministic hash. */
    public function byEmailHash(string $emailHash): ?PlatformManager
    {
        return $this->findOneBy(['emailHash' => $emailHash]);
    }

    /** @return list<PlatformManager> */
    public function all(): array
    {
        return $this->createQueryBuilder('m')->orderBy('m.createdAt', 'ASC')->getQuery()->getResult();
    }
}
