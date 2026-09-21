<?php

declare(strict_types=1);

namespace App\Module\Identity\Contract;

/**
 * Who belongs to a tenant, as plain data.
 *
 * The operator console needs to list a tenant's members in order to pick one to
 * impersonate. It gets rows, not `User` objects: handing out the entity would
 * let another module change a user without going through this one's commands.
 */
interface UserDirectoryInterface
{
    /** @return list<array{id: string, email: string, displayName: ?string, role: string, status: string}> */
    public function membersOf(string $tenantId): array;

    public function countMembersOf(string $tenantId): int;
}
