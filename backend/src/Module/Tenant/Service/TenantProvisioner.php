<?php

declare(strict_types=1);

namespace App\Module\Tenant\Service;

use App\Module\Tenant\Command\CreateTenant;
use App\Module\Tenant\Contract\TenantProvisionerInterface;
use App\Module\Tenant\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Cache\TenantCache;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The implementation behind the contract other modules import.
 *
 * Note what this buys: Identity says "create a tenant" without knowing that
 * doing so dispatches a command, runs every module's setup hooks, and emits an
 * event. All of that stays this module's business, and can change without
 * Identity noticing.
 */
final readonly class TenantProvisioner implements TenantProvisionerInterface
{
    public function __construct(
        private CommandBusInterface $commands,
        private TenantRepository $tenants,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
        private TenantCache $cache,
    ) {
    }

    public function create(string $slug, string $name, string $defaultLocale = 'en'): array
    {
        $created = $this->commands->dispatch(new CreateTenant($slug, $name, $defaultLocale));
        \assert(\is_array($created));

        return $created;
    }

    #[InfrastructureWrite(reason: 'activation is audited by the caller\'s command (identity.email.verified)')]
    public function activate(string $tenantId): void
    {
        // Unscoped because activation happens during verification, before any
        // session exists to establish a scope - and a tenant is not scoped to
        // itself.
        //
        // The write lives INSIDE the callback. `runUnscoped()` clears the
        // EntityManager on the way out, so a tenant loaded here and mutated
        // afterwards is mutated while detached: flush writes nothing and says
        // nothing. See [[unflushed-work-does-not-survive-rununscoped]].
        $activated = $this->scope->runUnscoped(
            'activating a tenant during email verification, before any scope exists',
            function () use ($tenantId): bool {
                $tenant = $this->tenants->get($tenantId)
                    ?? throw new NotFoundHttpException('No such tenant.');

                // Idempotent: verification links get clicked twice.
                if ($tenant->isActive()) {
                    return false;
                }

                $tenant->activate();
                $this->em->flush();

                return true;
            },
        );

        if ($activated) {
            $this->cache->invalidateTenant($tenantId);
        }
    }

    #[InfrastructureWrite(reason: 'the caller\'s command (manager.tenant.suspended) is the audited action')]
    public function suspend(string $tenantId): void
    {
        // Loaded, mutated and flushed inside the callback, for the reason given
        // in activate() above.
        $this->scope->runUnscoped(
            'an operator suspends a tenant from outside it',
            function () use ($tenantId): void {
                $tenant = $this->tenants->get($tenantId)
                    ?? throw new NotFoundHttpException('No such tenant.');

                $tenant->suspend();
                $this->em->flush();
            },
        );

        // TenantReader caches "is this tenant active" on every authenticated
        // request. Without this, a suspended tenant keeps working until the
        // entry expires - which is the whole point of suspending it.
        $this->cache->invalidateTenant($tenantId);
    }
}
