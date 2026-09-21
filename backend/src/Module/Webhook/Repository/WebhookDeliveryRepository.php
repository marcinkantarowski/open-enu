<?php

declare(strict_types=1);

namespace App\Module\Webhook\Repository;

use App\Module\Webhook\Entity\WebhookDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WebhookDelivery> */
class WebhookDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookDelivery::class);
    }

    public function get(string $id): ?WebhookDelivery
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /** @return list<WebhookDelivery> */
    public function recent(?string $endpointId = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('d')->orderBy('d.createdAt', 'DESC')->setMaxResults($limit);

        if ($endpointId !== null && Uuid::isValid($endpointId)) {
            $qb->andWhere('d.endpointId = :endpoint')->setParameter('endpoint', Uuid::fromString($endpointId));
        }

        return $qb->getQuery()->getResult();
    }
}
