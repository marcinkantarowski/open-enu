<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Contract;

/**
 * Marks a tenant's row as belonging to one unit of that tenant - a branch, a
 * department, a site (ADR-0025).
 *
 * The scope filter narrows such an entity to the units the caller may see:
 * `unit_id IN (...)`, or no predicate for someone who sees the whole tenant.
 * With no unit set in scope it matches nothing, exactly as a missing tenant does.
 *
 * A row with no unit belongs to the whole tenant and is seen only by those who
 * see the whole tenant. That is what lets an existing entity adopt this
 * interface without assigning every old row first.
 *
 * The entity must carry an indexed, nullable `unit_id` uuid column; the filter
 * and UnitWriteGuard read the column's value through this method.
 */
interface UnitScopedInterface extends TenantScopedInterface
{
    public function unitId(): ?string;
}
