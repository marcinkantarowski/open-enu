<?php

declare(strict_types=1);

namespace App\Module\Settings\Command;

use OpenEnu\Kernel\Command\CommandInterface;
use OpenEnu\Kernel\Command\TenantTargetedInterface;

/**
 * Changing a kill switch is exactly the kind of action someone asks about
 * afterwards, so it goes through the bus and lands in the audit trail with
 * before/after snapshots.
 */
final readonly class SetTenantFlag implements CommandInterface, TenantTargetedInterface
{
    public function __construct(
        public string $tenantId,
        public string $identifier,
        public mixed $value,
    ) {
    }

    public function auditAction(): string
    {
        return 'settings.flag.set';
    }

    public function auditSubjectId(): string
    {
        return $this->identifier;
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
