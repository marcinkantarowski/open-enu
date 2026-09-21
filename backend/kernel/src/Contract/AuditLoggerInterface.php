<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Contract;

/**
 * Where recorded changes go.
 *
 * The kernel produces audit entries from the command bus; a module decides what
 * happens to them. Until the Audit module exists (Phase 3) the kernel's own
 * implementation logs them, so audit coverage is real from the first command
 * rather than switched on later - a gap in an audit trail is invisible
 * precisely when it matters.
 *
 * Implementations MUST NOT throw: failing to record a change must not undo the
 * change. Swallow, log, and carry on.
 */
interface AuditLoggerInterface
{
    public function record(AuditEntry $entry): void;
}
