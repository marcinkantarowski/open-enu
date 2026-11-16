<?php

declare(strict_types=1);

namespace App\Module\Role\Service;

/**
 * Where PermissionResolver reads a tenant's stored roles from.
 *
 * An interface so the resolver's RULES can be tested against plain arrays; the
 * one implementation is RoleRepository.
 */
interface RoleGrantSource
{
    /** @return array<string, array{permissions: list<string>, known: list<string>, template: ?string}> by role key */
    public function grantsFor(string $tenantId): array;
}
