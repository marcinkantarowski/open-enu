<?php

declare(strict_types=1);

namespace App\Module\Tenant\Event;

use OpenEnu\Kernel\Event\DomainEvent;

/**
 * A tenant now exists. Other modules react to this rather than being called by
 * the Tenant module, which is what keeps the dependency pointing one way.
 */
final readonly class TenantCreated extends DomainEvent
{
    public function __construct(
        string $tenantId,
        public string $slug,
        public string $name,
    ) {
        parent::__construct($tenantId);
    }

    public function eventName(): string
    {
        return 'tenant.created';
    }

    public function payload(): array
    {
        return ['tenantId' => $this->subjectId, 'slug' => $this->slug, 'name' => $this->name];
    }
}
