<?php

declare(strict_types=1);

namespace App\Module\Manager\Command;

use OpenEnu\Kernel\Command\CommandInterface;
use OpenEnu\Kernel\Command\TenantTargetedInterface;

/**
 * Recorded whether or not the token is successfully minted.
 *
 * A refused attempt is as interesting as a successful one: it is how "an
 * operator tried to view an account they had no business in" becomes visible.
 */
final readonly class StartImpersonation implements CommandInterface, TenantTargetedInterface
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public string $operatorId,
    ) {
    }

    public function auditAction(): string
    {
        return 'manager.impersonation.started';
    }

    public function auditSubjectId(): string
    {
        return $this->userId;
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
