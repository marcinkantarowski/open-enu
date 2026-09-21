<?php

declare(strict_types=1);

namespace App\Module\Attachment\Repository;

use App\Module\Attachment\Entity\Attachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Attachment> */
final class AttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attachment::class);
    }

    public function get(string $id): ?Attachment
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * Uploads nobody ever attached to anything.
     *
     * A file uploaded and then abandoned - the form was closed, the request
     * failed after the upload - otherwise accumulates forever and is invisible,
     * because nothing references it.
     *
     * @return list<Attachment>
     */
    public function orphansOlderThan(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.ownerId IS NULL')
            ->andWhere('a.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }
}
