<?php

declare(strict_types=1);

namespace App\Module\Progress\Repository;

use App\Module\Progress\Entity\ProgressJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProgressJob> */
final class ProgressJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgressJob::class);
    }
}
