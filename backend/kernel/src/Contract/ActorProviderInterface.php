<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Contract;

/**
 * Who is making the change.
 *
 * The kernel records audit entries but has no idea what a user is - that is the
 * Identity module's business (Phase 3), which implements this. Until then the
 * kernel's null implementation reports nobody, and audit entries are honest
 * about it rather than inventing an actor.
 */
interface ActorProviderInterface
{
    /** Stable identifier of the acting principal, or null for a system action. */
    public function actorId(): ?string;

    /**
     * The operator behind an impersonated session, if any (ADR-0008).
     *
     * An impersonated change must be attributable to BOTH identities, or the
     * audit trail says a customer did something their support agent did.
     */
    public function onBehalfOfId(): ?string;
}
