<?php

declare(strict_types=1);

namespace App\Module\Webhook\Repository;

use App\Module\Webhook\Entity\WebhookEndpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WebhookEndpoint> */
class WebhookEndpointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookEndpoint::class);
    }

    public function get(string $id): ?WebhookEndpoint
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /** @return list<WebhookEndpoint> */
    public function all(): array
    {
        return $this->createQueryBuilder('e')->orderBy('e.createdAt', 'DESC')->getQuery()->getResult();
    }

    /**
     * Every active endpoint that asked for this event.
     *
     * Filtered in PHP rather than with a JSONB containment query on purpose: a
     * tenant has a handful of endpoints, the list is already scoped, and the
     * predicate is then one obvious line instead of operator syntax that only
     * works on Postgres.
     *
     * @return list<WebhookEndpoint>
     */
    public function subscribedTo(string $eventName): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (WebhookEndpoint $e): bool => $e->wants($eventName),
        ));
    }
}
