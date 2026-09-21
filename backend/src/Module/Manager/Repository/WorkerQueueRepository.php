<?php

declare(strict_types=1);

namespace App\Module\Manager\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use OpenEnu\Kernel\Attribute\Unscoped;

/**
 * Queue depth, read from Messenger's own tables.
 *
 * Not a Doctrine repository: `messenger_messages` belongs to the framework
 * rather than to any module's entity, which is also why it is excluded from
 * `doctrine:schema:validate`. Building an entity for it would fight that
 * exclusion and claim ownership of a table Messenger manages.
 */
final readonly class WorkerQueueRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<array{queue: string, waiting: int, oldestWaiting: ?string, lagSeconds: int}> */
    #[Unscoped(reason: 'queue depth is platform infrastructure and has no tenant')]
    public function queueDepths(): array
    {
        $rows = [];

        try {
            $queues = $this->db->fetchAllAssociative(
                'SELECT queue_name, count(*) AS waiting, min(created_at) AS oldest
                 FROM messenger_messages
                 WHERE delivered_at IS NULL
                 GROUP BY queue_name',
            );
        } catch (TableNotFoundException) {
            return [];
        }

        foreach ($queues as $row) {
            $oldest = \is_string($row['oldest']) ? new \DateTimeImmutable($row['oldest']) : null;

            $rows[] = [
                'queue' => (string) $row['queue_name'],
                'waiting' => (int) $row['waiting'],
                'oldestWaiting' => $oldest?->format(\DATE_ATOM),
                // Lag is the number that matters: a large backlog draining
                // steadily is fine, a small one that never moves is not.
                'lagSeconds' => $oldest !== null ? time() - $oldest->getTimestamp() : 0,
            ];
        }

        return $rows;
    }

    #[Unscoped(reason: 'dead letters are platform infrastructure and have no tenant')]
    public function failedCount(): int
    {
        try {
            return (int) $this->db->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'failed'");
        } catch (TableNotFoundException) {
            return 0;
        }
    }
}
