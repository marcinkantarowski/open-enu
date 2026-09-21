<?php

declare(strict_types=1);

namespace App\Module\Settings\Setup;

use OpenEnu\Kernel\Setup\TenantSetupInterface;

/**
 * Nothing per tenant.
 *
 * Overrides are created on demand, and absence means "inherit the global
 * default" - so seeding one row per setting per tenant would produce thousands
 * of rows that all say the same thing as the default, and then pin that value
 * when the default later changes.
 */
final readonly class SettingsSetup implements TenantSetupInterface
{
    public function onTenantCreated(string $tenantId): void
    {
    }

    public function seedExamples(string $tenantId): void
    {
    }
}
