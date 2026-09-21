<?php

declare(strict_types=1);

namespace App\Module\Audit\Service;

use App\Module\Audit\Entity\AuditEntry as AuditEntryRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use OpenEnu\Kernel\Attribute\Unscoped;
use OpenEnu\Kernel\Contract\AuditEntry;
use OpenEnu\Kernel\Contract\AuditLoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\Uid\Uuid;

/**
 * Persists audit entries, replacing the kernel's log-only default.
 *
 * Written with DBAL rather than the ORM, deliberately. The command bus records a
 * FAILED command after its transaction has rolled back; an ORM write at that
 * point would be queued into a unit of work that is already being discarded, so
 * the record of the failure would vanish along with the failure - losing exactly
 * the entries most worth having.
 *
 * It also never throws. Failing to record a change must not undo the change.
 */
#[AsDecorator(decorates: 'OpenEnu\Kernel\Command\LoggingAuditLogger')]
final readonly class PersistentAuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private AuditLoggerInterface $inner,
        private Connection $db,
        private LoggerInterface $logger,
    ) {
    }

    #[Unscoped(reason: 'audit rows are written outside any transaction and are not tenant-filtered by design')]
    public function record(AuditEntry $entry): void
    {
        // Still logs: a structured log line is what an operator greps during an
        // incident, and it survives even if the database is the thing failing.
        $this->inner->record($entry);

        try {
            $this->db->insert('audit_entry', [
                'id' => (string) Uuid::v7(),
                'action' => $entry->action,
                'tenant_id' => $entry->tenantId,
                'actor_id' => $entry->actorId,
                'on_behalf_of_id' => \is_string($entry->context['on_behalf_of'] ?? null) ? $entry->context['on_behalf_of'] : null,
                'subject_id' => $entry->subjectId,
                'request_id' => $entry->requestId,
                'succeeded' => $entry->succeeded ? 1 : 0,
                'failure_reason' => $entry->failureReason,
                'before' => $entry->before !== null ? json_encode($entry->before) : null,
                'after' => $entry->after !== null ? json_encode($entry->after) : null,
                'context' => json_encode($entry->context) ?: '{}',
                'recorded_at' => ($entry->at ?? new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            ], [
                'succeeded' => ParameterType::BOOLEAN,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('audit.persist_failed', [
                'action' => $entry->action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
