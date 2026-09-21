<?php

declare(strict_types=1);

namespace App\Module\Webhook\Setup;

use OpenEnu\Kernel\Setup\TenantSetupInterface;

/**
 * Nothing per tenant.
 *
 * An endpoint is a URL somebody else operates; inventing one for a new tenant
 * would mean inventing a destination for their data.
 */
final readonly class WebhookSetup implements TenantSetupInterface
{
    public function onTenantCreated(string $tenantId): void
    {
    }

    public function seedExamples(string $tenantId): void
    {
    }
}
