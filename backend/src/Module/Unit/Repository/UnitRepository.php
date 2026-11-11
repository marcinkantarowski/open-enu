<?php

declare(strict_types=1);

namespace App\Module\Unit\Repository;

use App\Module\Unit\Entity\Unit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Not final: see ProjectRepository - a final repository cannot be doubled.
 *
 * @extends ServiceEntityRepository<Unit>
 */
class UnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Unit::class);
    }

    public function get(string $id): ?Unit
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * The whole tree, parents before children - path order is depth-first order.
     *
     * @return list<Unit>
     */
    public function tree(): array
    {
        return $this->createQueryBuilder('u')->orderBy('u.path', 'ASC')->getQuery()->getResult();
    }

    /**
     * Everything strictly below $unit.
     *
     * @return list<Unit>
     */
    public function below(Unit $unit): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.path LIKE :prefix')
            ->andWhere('u.id != :id')
            ->setParameter('prefix', addcslashes($unit->path(), '%_') . '%')
            ->setParameter('id', $unit->id())
            ->getQuery()
            ->getResult();
    }

    /**
     * The ids of these units and of every unit below them. Unknown ids - from
     * another tenant, or made up - expand to nothing.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function idsUnder(array $ids): array
    {
        $ids = array_values(array_filter($ids, Uuid::isValid(...)));

        if ($ids === []) {
            return [];
        }

        $roots = $this->createQueryBuilder('u')
            ->select('u.path')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleColumnResult();

        if ($roots === []) {
            return [];
        }

        $query = $this->createQueryBuilder('u')->select('u.id');
        $any = $query->expr()->orX();

        foreach (array_values($roots) as $i => $path) {
            $any->add('u.path LIKE :p' . $i);
            $query->setParameter('p' . $i, addcslashes((string) $path, '%_') . '%');
        }

        return array_values(array_map('strval', $query->andWhere($any)->getQuery()->getSingleColumnResult()));
    }
}
