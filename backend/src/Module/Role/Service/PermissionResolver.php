<?php

declare(strict_types=1);

namespace App\Module\Role\Service;

use App\Module\Role\Contract\PermissionsInterface;
use OpenEnu\Kernel\Module\ModuleRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What a role may do in a tenant, in one place (ADR-0025).
 *
 * Two callers need this answer and they must never disagree: the voter, which
 * ENFORCES it on every request, and the session payload, which tells the UI
 * which buttons to render. Computing it twice would drift - and the drift is
 * silent in the worst direction, showing a control that then 403s.
 *
 * The vocabulary comes from the modules themselves (`Acl/permissions.php`); the
 * grants come from the tenant's roles. A tenant's roles are read once per
 * request and per message - not cached across them, so an edit applies to the
 * very next request with nothing to invalidate.
 */
final class PermissionResolver implements PermissionsInterface, ResetInterface
{
    /**
     * Capabilities no role but the owner can hold, whatever a tenant ticks.
     *
     * Both are about self-preservation of the tenant: deleting it, and minting
     * credentials that outlive the person who made them.
     */
    public const array OWNER_ONLY = [
        'tenant.delete',
        'api_key.manage',
    ];

    /** @var array<string, array<string, array{permissions: list<string>, known: list<string>, template: ?string}>> */
    private array $grants = [];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly RoleGrantSource $roles,
    ) {
    }

    public function allows(string $tenantId, string $role, string $permission): bool
    {
        // An undeclared permission is refused rather than granted. A typo in a
        // controller attribute then fails closed, loudly, instead of opening an
        // endpoint to everyone.
        if ($this->modules->ownerOf($permission) === null) {
            return false;
        }

        if ($role === PermissionsInterface::OWNER) {
            return true;
        }

        if (\in_array($permission, self::OWNER_ONLY, true)) {
            return false;
        }

        $this->grants[$tenantId] ??= $this->roles->grantsFor($tenantId);
        $stored = $this->grants[$tenantId][$role] ?? null;

        // A built-in role this tenant never edited behaves as its template.
        // Any other key with no row is a role that was deleted or never
        // existed: nothing.
        if ($stored === null) {
            return \in_array($role, self::BUILT_IN, true) && self::template($role, $permission);
        }

        if (\in_array($permission, $stored['permissions'], true)) {
            return true;
        }

        // Unticked when the role was saved: a decision. Declared since: the
        // template decides, until someone saves the role again.
        return !\in_array($permission, $stored['known'], true)
            && $stored['template'] !== null
            && self::template($stored['template'], $permission);
    }

    /**
     * Everything this role may do, for the client to hide what it cannot - and
     * for the roles screen to show what a role holds right now.
     *
     * @return list<string>
     */
    public function forRole(string $tenantId, string $role): array
    {
        $granted = array_values(array_filter(
            array_keys($this->modules->permissions()),
            fn (string $permission): bool => $this->allows($tenantId, $role, $permission),
        ));

        sort($granted);

        return $granted;
    }

    /**
     * What a tenant may tick on a role: every declared permission but the owner's.
     *
     * @return list<string>
     */
    public function assignable(): array
    {
        $assignable = array_values(array_diff(array_keys($this->modules->permissions()), self::OWNER_ONLY));
        sort($assignable);

        return $assignable;
    }

    /**
     * Every permission that exists right now - what a role saved now has decided on.
     *
     * @return list<string>
     */
    public function declared(): array
    {
        return array_keys($this->modules->permissions());
    }

    /**
     * Refuses anything a tenant may not tick: undeclared, or the owner's alone.
     *
     * @param list<string> $permissions
     */
    public function assertAssignable(array $permissions): void
    {
        $refused = array_values(array_diff($permissions, $this->assignable()));
        if ($refused !== []) {
            throw new BadRequestHttpException(sprintf('A role cannot hold: %s.', implode(', ', $refused)));
        }
    }

    /** After a role changes, so the same request already answers from the new grants. */
    public function forget(string $tenantId): void
    {
        unset($this->grants[$tenantId]);
    }

    public function reset(): void
    {
        $this->grants = [];
    }

    /** The built-in rules - what `admin` and `member` mean until a tenant changes them. */
    private static function template(string $template, string $permission): bool
    {
        return match ($template) {
            PermissionsInterface::ADMIN => true,
            // A plain member reads, and does the everyday work a module was
            // built for - which the module says by naming a permission `.use`.
            // Anything else that changes state needs a role someone
            // deliberately granted.
            PermissionsInterface::MEMBER => str_ends_with($permission, '.view') || str_ends_with($permission, '.use'),
            default => false,
        };
    }
}
