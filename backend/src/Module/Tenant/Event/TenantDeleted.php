<?php

declare(strict_types=1);

namespace App\Module\Tenant\Event;

use OpenEnu\Kernel\Event\DomainEvent;

/**
 * A tenant is going away. Every module holding its data must clean up.
 *
 * The listeners are what make deletion complete without the Tenant module
 * knowing what anyone else stores - it cannot, and should not.
 */
final readonly class TenantDeleted extends DomainEvent
{
    public function eventName(): string
    {
        return 'tenant.deleted';
    }

    public function payload(): array
    {
        return ['tenantId' => $this->subjectId];
    }
}
