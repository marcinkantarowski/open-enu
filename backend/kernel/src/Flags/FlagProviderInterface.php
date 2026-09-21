<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Flags;

/**
 * Implemented by a module to declare the flags it reads.
 *
 * A kernel contract rather than one owned by the Settings module, for the same
 * reason `TenantSetupInterface` is: the kernel defines the extension point and a
 * module implements the persistence behind it. A module declaring a flag then
 * depends on the kernel - which everything already does - instead of gaining a
 * dependency on Settings.
 *
 * Declarations are reconciled into storage by `app:flags:sync`, which is
 * idempotent and never touches a tenant's overrides.
 *
 * Tagged by the kernel's AUTOCONFIGURED_TAGS map, where every extension point is
 * listed together - implement the interface and nothing else is needed.
 */
interface FlagProviderInterface
{
    /** @return iterable<FlagDefinition> */
    public function flags(): iterable;
}
