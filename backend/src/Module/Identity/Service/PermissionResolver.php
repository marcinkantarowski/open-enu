<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Entity\Membership;
use OpenEnu\Kernel\Module\ModuleRegistry;

/**
 * What a role may do, in one place.
 *
 * Two callers need this answer and they must never disagree: the voter, which
 * ENFORCES it on every request, and the session payload, which tells the UI
 * which buttons to render. Computing it twice would drift - and the drift is
 * silent in the worst direction, showing a control that then 403s.
 *
 * The vocabulary comes from the modules themselves (`Acl/permissions.php`);
 * nothing here has a list of what exists.
 */
final readonly class PermissionResolver
{
    /**
     * Capabilities an admin does NOT get, even though they get everything else.
     *
     * Both are about self-preservation of the tenant: deleting it, and minting
     * credentials that outlive the person who made them.
     */
    public const array OWNER_ONLY = [
        'tenant.delete',
        'api_key.manage',
    ];

    public function __construct(private ModuleRegistry $modules)
    {
    }

    public function allows(string $role, string $permission): bool
    {
        // An undeclared permission is refused rather than granted. A typo in a
        // controller attribute then fails closed, loudly, instead of opening an
        // endpoint to everyone.
        if ($this->modules->ownerOf($permission) === null) {
            return false;
        }

        return match ($role) {
            Membership::ROLE_OWNER => true,
            Membership::ROLE_ADMIN => !\in_array($permission, self::OWNER_ONLY, true),
            // A plain member reads. Anything that changes state needs a role
            // someone deliberately granted.
            Membership::ROLE_MEMBER => str_ends_with($permission, '.view'),
            default => false,
        };
    }

    /**
     * Everything this role may do, for the client to hide what it cannot.
     *
     * Advisory only: the server checks every request regardless. A client that
     * lied to itself about this would get a 403, not access.
     *
     * @return list<string>
     */
    public function forRole(string $role): array
    {
        $granted = array_values(array_filter(
            array_keys($this->modules->permissions()),
            fn (string $permission): bool => $this->allows($role, $permission),
        ));

        sort($granted);

        return $granted;
    }
}
