<?php

declare(strict_types=1);

namespace App\Module\Tenant\Command;

use OpenEnu\Kernel\Command\CommandInterface;
use OpenEnu\Kernel\Command\TenantTargetedInterface;

final readonly class DeleteTenant implements CommandInterface, TenantTargetedInterface
{
    public function __construct(public string $tenantId)
    {
    }

    public function auditAction(): string
    {
        return 'tenant.deleted';
    }

    public function auditSubjectId(): string
    {
        return $this->tenantId;
    }

    /**
     * Named explicitly because this can be dispatched from the manager realm,
     * where there is no tenant in scope to infer it from.
     */
    public function auditTenantId(): string
    {
        return $this->tenantId;
    }
}
