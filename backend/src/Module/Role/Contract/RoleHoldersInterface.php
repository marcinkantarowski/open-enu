<?php

declare(strict_types=1);

namespace App\Module\Role\Contract;

/**
 * How many people hold a role - asked before a role is deleted.
 *
 * Declared here and implemented by the module that keeps memberships, so this
 * module never reads them: it depends on nobody, and a deleted role can never
 * leave a person pointing at nothing.
 */
interface RoleHoldersInterface
{
    public function holding(string $tenantId, string $role): int;
}
