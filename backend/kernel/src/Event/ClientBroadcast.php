<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Event;

/**
 * Marks a domain event as safe to push to the browser.
 *
 * Opt-in, never automatic. A domain event is internal by default; broadcasting
 * one means its `payload()` reaches every user of that tenant with an open
 * connection, and the mistake is invisible until someone reads their own data in
 * a colleague's browser.
 *
 * Broadcast events go to `/tenants/{tid}/events`, so a subscriber can only
 * receive their own tenant's (.ai/platform/PLAN.md §6.5).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ClientBroadcast
{
    public function __construct(
        /** Extra permission a viewer needs before the payload is delivered. */
        public ?string $requiresPermission = null,
    ) {
    }
}
