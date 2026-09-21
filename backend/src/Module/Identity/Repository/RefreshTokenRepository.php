<?php

declare(strict_types=1);

namespace App\Module\Identity\Repository;

use App\Module\Identity\Entity\RefreshToken;
use App\Module\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RefreshToken> */
final class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function byHash(string $hash): ?RefreshToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /** @return list<RefreshToken> */
    public function family(string $family): array
    {
        return $this->findBy(['family' => $family]);
    }

    /** @return list<RefreshToken> */
    public function forUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    public function purgeExpired(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
