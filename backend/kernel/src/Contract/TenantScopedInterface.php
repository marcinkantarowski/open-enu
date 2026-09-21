<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Contract;

/**
 * Marks an entity as belonging to exactly one tenant.
 *
 * Implementing this is what puts an entity under the scope filter: every query
 * gains a `tenant_id = ?` predicate automatically, and with no tenant in
 * context the query returns nothing rather than everything (ADR-0004).
 *
 * The entity must carry an indexed `tenant_id` column. The filter reads the
 * column, not this method - the method exists so application code can ask, and
 * so the arch test can enumerate implementors and verify filter coverage.
 */
interface TenantScopedInterface
{
    public function tenantId(): string;
}
