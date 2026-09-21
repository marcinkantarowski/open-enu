<?php

declare(strict_types=1);

namespace App\Module\Audit\Contract;

/**
 * Reading the audit trail, for the operator console.
 *
 * Rows, not entities. An audit entry is append-only, so handing out the object
 * would offer a setter that must never be called - and the only defence would be
 * everyone remembering not to.
 */
interface AuditReaderInterface
{
    /**
     * @param string|null $tenantId null reads across every tenant, which only
     *                              the operator realm is allowed to ask for
     *
     * @return list<array<string, mixed>>
     */
    public function recent(?string $tenantId, int $limit = 50): array;
}
