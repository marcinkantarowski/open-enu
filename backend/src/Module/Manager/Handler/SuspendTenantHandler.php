<?php

declare(strict_types=1);

namespace App\Module\Manager\Handler;

use App\Module\Manager\Command\SuspendTenant;
use App\Module\Tenant\Contract\TenantProvisionerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Suspension itself belongs to the Tenant module - what "suspended" means, and
 * what has to be invalidated for it to take effect, is its business. This
 * handler exists so the ACTION is audited as an operator decision.
 */
#[AsMessageHandler]
final readonly class SuspendTenantHandler
{
    public function __construct(private TenantProvisionerInterface $tenants)
    {
    }

    public function __invoke(SuspendTenant $command): void
    {
        $this->tenants->suspend($command->tenantId);
    }
}
