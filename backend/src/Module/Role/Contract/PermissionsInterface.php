<?php

declare(strict_types=1);

namespace App\Module\Role\Contract;

/**
 * What a role may do in a tenant (ADR-0025) - the one answer the voter enforces
 * and the session shows. Roles are named by key; three exist in every tenant.
 */
interface PermissionsInterface
{
    /** Every permission, unchangeable, and a tenant always keeps one. */
    public const string OWNER = 'owner';

    /** Everything but the owner's own, until the tenant changes it. */
    public const string ADMIN = 'admin';

    /** Reads, and does a module's `.use` work, until the tenant changes it. */
    public const string MEMBER = 'member';

    public const array BUILT_IN = [self::OWNER, self::ADMIN, self::MEMBER];

    /** A built-in key, or one of this tenant's own roles. */
    public function exists(string $tenantId, string $role): bool;

    public function allows(string $tenantId, string $role, string $permission): bool;

    /** @return list<string> everything the role may do, sorted */
    public function forRole(string $tenantId, string $role): array;
}
