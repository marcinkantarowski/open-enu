<?php

declare(strict_types=1);

namespace App\Module\Notification\Setup;

use OpenEnu\Kernel\Setup\TenantSetupInterface;

/** Nothing per tenant: a feed starts empty, and a seeded notification is a lie. */
final readonly class NotificationSetup implements TenantSetupInterface
{
    public function onTenantCreated(string $tenantId): void
    {
    }

    public function seedExamples(string $tenantId): void
    {
    }
}
