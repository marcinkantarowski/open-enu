<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Setup;

/**
 * What a module needs in place before a new tenant can use it.
 *
 * Run in dependency order when a tenant is created, so a module can rely on the
 * modules it depends on having already run.
 *
 * **Both methods must be idempotent.** Provisioning is retried - a failed
 * signup, a partially-completed wizard, a re-run after a fix - and a second run
 * that duplicates its defaults turns one bad signup into permanent bad data.
 */
interface TenantSetupInterface
{
    /** Rows the module cannot function without: default roles, settings, categories. */
    public function onTenantCreated(string $tenantId): void;

    /**
     * Sample data, for demos and development.
     *
     * Separate from onTenantCreated because production tenants want the former
     * and not the latter, and merging them is how demo data reaches a customer.
     */
    public function seedExamples(string $tenantId): void;
}
