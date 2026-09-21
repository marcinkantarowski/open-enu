<?php

declare(strict_types=1);

namespace App\Module\Tenant\Handler;

use App\Module\Tenant\Command\DeleteTenant;
use App\Module\Tenant\Event\TenantDeleted;
use App\Module\Tenant\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Cache\TenantCache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class DeleteTenantHandler
{
    public function __construct(
        private TenantRepository $tenants,
        private EntityManagerInterface $em,
        private TenantCache $cache,
        private MessageBusInterface $events,
    ) {
    }

    public function __invoke(DeleteTenant $command): void
    {
        $tenant = $this->tenants->get($command->tenantId)
            ?? throw new NotFoundHttpException('No such tenant.');

        // Announce BEFORE deleting: listeners need the tenant's rows to still be
        // resolvable while they clean up their own. The event rides the outbox,
        // so it commits with this transaction or not at all.
        $this->events->dispatch(new TenantDeleted($command->tenantId));

        $this->em->remove($tenant);
        $this->em->flush();

        // One tag drops everything anything cached for this tenant. Without it,
        // a recreated slug could serve the deleted tenant's cached data.
        $this->cache->invalidateTenant($command->tenantId);
    }
}
