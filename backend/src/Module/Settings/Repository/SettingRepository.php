<?php

declare(strict_types=1);

namespace App\Module\Settings\Repository;

use App\Module\Settings\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Setting> */
final class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function byIdentifier(string $identifier): ?Setting
    {
        return $this->findOneBy(['identifier' => $identifier]);
    }

    /** @return list<Setting> */
    public function all(): array
    {
        return $this->createQueryBuilder('s')->orderBy('s.identifier', 'ASC')->getQuery()->getResult();
    }
}
