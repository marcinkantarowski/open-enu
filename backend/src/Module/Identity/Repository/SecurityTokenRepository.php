<?php

declare(strict_types=1);

namespace App\Module\Identity\Repository;

use App\Module\Identity\Entity\SecurityToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SecurityToken> */
final class SecurityTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityToken::class);
    }

    /** Looked up by hash, never by the raw value - the raw value is not stored. */
    public function byHash(string $tokenHash, string $purpose): ?SecurityToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash, 'purpose' => $purpose]);
    }

    /** @return list<SecurityToken> */
    public function expiredBefore(\DateTimeImmutable $when): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.expiresAt < :when')
            ->setParameter('when', $when)
            ->getQuery()
            ->getResult();
    }
}
