<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Command;

use Psr\Log\LoggerInterface;
use OpenEnu\Kernel\Contract\AuditEntry;
use OpenEnu\Kernel\Contract\AuditLoggerInterface;

/**
 * Writes audit entries to the structured log.
 *
 * A real default, not a no-op: the Audit module (Phase 3) will persist entries
 * to a queryable table, but until then every command is still recorded
 * somewhere a human can find it. A system that starts auditing "later" has a
 * hole in its history exactly where the early, formative changes were made.
 */
final readonly class LoggingAuditLogger implements AuditLoggerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function record(AuditEntry $entry): void
    {
        // Never throws: failing to record a change must not undo the change.
        try {
            $this->logger->info('audit', [
                'action' => $entry->action,
                'subject' => $entry->subjectId,
                'actor' => $entry->actorId,
                'tenant' => $entry->tenantId,
                'request_id' => $entry->requestId,
                'succeeded' => $entry->succeeded,
                'failure' => $entry->failureReason,
                'before' => $entry->before,
                'after' => $entry->after,
                ...$entry->context,
            ]);
        } catch (\Throwable) {
            // Deliberately swallowed - see AuditLoggerInterface.
        }
    }
}
