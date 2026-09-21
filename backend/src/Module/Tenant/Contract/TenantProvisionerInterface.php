<?php

declare(strict_types=1);

namespace App\Module\Tenant\Contract;

/**
 * Creating and activating tenants, for modules that need to.
 *
 * Identity needs both - signup creates a tenant, verification activates it - and
 * reaching for `Tenant\Command\CreateTenant` or `Tenant\Repository` directly is
 * a build failure (ADR-0002). This is the published surface instead.
 *
 * It returns plain data rather than the entity: handing out the entity would let
 * another module mutate a tenant without going through this module's commands,
 * which is exactly the coupling the boundary exists to prevent.
 */
interface TenantProvisionerInterface
{
    /** @return array<string, mixed> the created tenant, as data */
    public function create(string $slug, string $name, string $defaultLocale = 'en'): array;

    /**
     * Make a pending tenant usable.
     *
     * Idempotent: an already-active tenant is left alone, because verification
     * links get clicked twice.
     */
    public function activate(string $tenantId): void;

    /**
     * Stop a tenant being usable, without deleting anything.
     *
     * Idempotent, and it invalidates the cached "is this tenant active" answer -
     * otherwise a suspension takes effect whenever the cache entry expires,
     * which defeats the point of suspending it.
     */
    public function suspend(string $tenantId): void;
}
