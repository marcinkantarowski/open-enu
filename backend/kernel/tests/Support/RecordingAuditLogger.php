<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Support;

use OpenEnu\Kernel\Contract\AuditEntry;
use OpenEnu\Kernel\Contract\AuditLoggerInterface;

/**
 * Collects audit entries so a test can assert on them.
 *
 * A named double rather than an anonymous class with a by-reference property:
 * the reference version works but is opaque to static analysis, which then
 * reports the collector as write-only.
 */
final class RecordingAuditLogger implements AuditLoggerInterface
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public function record(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<AuditEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_map(static fn (AuditEntry $e): string => $e->action, $this->entries);
    }
}
