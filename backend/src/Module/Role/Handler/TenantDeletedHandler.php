<?php

declare(strict_types=1);

namespace App\Module\Role\Handler;

use App\Module\Role\Repository\RoleRepository;
use App\Module\Tenant\Event\TenantDeleted;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** A deleted tenant's roles go with it; the table has no foreign key to cascade (ADR-0002). */
#[AsMessageHandler]
final readonly class TenantDeletedHandler
{
    public function __construct(
        private RoleRepository $roles,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TenantDeleted $event): void
    {
        $this->logger->info('role.tenant_deleted.cleanup', [
            'tenant' => $event->subjectId,
            'roles_removed' => $this->roles->deleteForTenant($event->subjectId),
        ]);
    }
}
